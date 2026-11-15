<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Network\Database\Factories\NodeFactory;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Telemetry\Infrastructure\LiveState\FleetPlateResolver;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Factory\VehiclePositionFactory;

/**
 * `GET /api/v1/app/telemetry/fleet/live` contra el stack completo: alcance por rol y ciudad (el mismo de los
 * canales `fleet.all` / `fleet.city.<departamento>`), ETag atado al alcance, exclusión de los vehículos dados
 * de baja en flota y resolver de placas sin memoria de proceso.
 *
 * Se omite el middleware de puerta (patrón F): el alcance sale del usuario autenticado, no de la ruta.
 */
const FLH_URL = '/api/v1/app/telemetry/fleet/live';
const FLH_RECORDED_AT = '2026-11-15T14:00:00+00:00';

beforeEach(function (): void {
    $this->withoutMiddleware();
    config([
        'logistics-telemetry.live.cache_store' => 'array',
        'logistics-telemetry.live.retired_statuses' => ['inactive'],
        'logistics-shipments.scope.strict_without_city' => false,
    ]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/** Camión con sede en un nodo de ese departamento y una posición en vivo. Devuelve su id. */
function flhTruck(Department $department, string $status = 'available'): string
{
    $node = NodeFactory::new()->inDepartment($department)->create();
    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create(['status' => $status]);
    $vehicleId = (string) $vehicle->getKey();

    VehiclePositionFactory::new()->create([
        'vehicle_id' => $vehicleId,
        'recorded_at' => new DateTimeImmutable(FLH_RECORDED_AT),
    ]);

    return $vehicleId;
}

/** Usuario de la app con ese rol y esa ciudad (código de departamento, o null). */
function flhUser(Role $role, ?string $city = null): User
{
    $user = User::factory()->create(['city' => $city]);
    $user->assignRole($role);

    return $user;
}

/**
 * Ids de los camiones que ve esa persona en `fleet/live`.
 *
 * @return list<string>
 */
function flhVisible(object $test, User $user, string $query = ''): array
{
    $ids = collect($test->actingAs($user)->getJson(FLH_URL.$query)->assertOk()->json('data'))
        ->pluck('vehicle_id')
        ->sort()
        ->values()
        ->all();

    /** @var list<string> $ids */
    return $ids;
}

it('admin y supervisor ven los camiones de todas las ciudades', function (): void {
    $santaCruz = flhTruck(Department::SantaCruz);
    $laPaz = flhTruck(Department::LaPaz);
    $expected = collect([$santaCruz, $laPaz])->sort()->values()->all();

    expect(flhVisible($this, flhUser(Role::Admin)))->toBe($expected)
        // La ciudad del supervisor no lo acota.
        ->and(flhVisible($this, flhUser(Role::Supervisor, 'la_paz')))->toBe($expected);
});

it('el planificador con ciudad ve solo los camiones con sede en su departamento', function (): void {
    $santaCruz = flhTruck(Department::SantaCruz);
    $otherSantaCruz = flhTruck(Department::SantaCruz);
    flhTruck(Department::LaPaz);
    // Posición de un vehículo que flota no conoce: sin sede, no pertenece a ninguna ciudad.
    VehiclePositionFactory::new()->create();

    $planner = flhUser(Role::Planificador, 'santa_cruz');
    $response = $this->actingAs($planner)->getJson(FLH_URL)->assertOk();

    expect(collect($response->json('data'))->pluck('vehicle_id')->sort()->values()->all())
        ->toBe(collect([$santaCruz, $otherSantaCruz])->sort()->values()->all())
        ->and(collect($response->json('data'))->pluck('city')->unique()->values()->all())->toBe(['santa_cruz'])
        ->and($response->json('meta.count'))->toBe(2);

    // El polling incremental respeta el mismo alcance.
    $past = rawurlencode((new DateTimeImmutable('-1 hour'))->format(DATE_ATOM));
    expect(flhVisible($this, $planner, '?since='.$past))
        ->toBe(collect([$santaCruz, $otherSantaCruz])->sort()->values()->all());
});

it('el planificador sin ciudad ve todas, y en modo estricto recibe la lista vacía', function (): void {
    $santaCruz = flhTruck(Department::SantaCruz);
    $laPaz = flhTruck(Department::LaPaz);
    $planner = flhUser(Role::Planificador);

    expect(flhVisible($this, $planner))->toBe(collect([$santaCruz, $laPaz])->sort()->values()->all());

    config(['logistics-shipments.scope.strict_without_city' => true]);

    $this->actingAs($planner)->getJson(FLH_URL)
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.count', 0);
});

it('cualquier otro rol recibe la lista vacía aunque llegue al endpoint', function (): void {
    flhTruck(Department::SantaCruz);

    expect(flhVisible($this, flhUser(Role::Chofer, 'santa_cruz')))->toBe([])
        ->and(flhVisible($this, flhUser(Role::Tienda, 'santa_cruz')))->toBe([]);
});

it('el ETag depende del alcance: con los mismos datos, dos alcances distintos no comparten validador', function (): void {
    // Toda la flota está en Santa Cruz: admin y planificador de Santa Cruz reciben los mismos datos.
    flhTruck(Department::SantaCruz);
    $admin = flhUser(Role::Admin);
    $planner = flhUser(Role::Planificador, 'santa_cruz');

    $asAdmin = $this->actingAs($admin)->getJson(FLH_URL)->assertOk();
    $asPlanner = $this->actingAs($planner)->getJson(FLH_URL)->assertOk();

    $adminEtag = (string) $asAdmin->headers->get('ETag');
    $plannerEtag = (string) $asPlanner->headers->get('ETag');

    expect($asPlanner->json('data'))->toBe($asAdmin->json('data'))
        ->and($adminEtag)->not->toBe('')
        ->and($plannerEtag)->not->toBe($adminEtag);

    // Cada uno revalida con el suyo (304) y no con el del otro (200).
    $this->actingAs($planner)->getJson(FLH_URL, ['If-None-Match' => $plannerEtag])->assertStatus(304);
    $this->actingAs($planner)->getJson(FLH_URL, ['If-None-Match' => $adminEtag])->assertOk();
    $this->actingAs($admin)->getJson(FLH_URL, ['If-None-Match' => $adminEtag])->assertStatus(304);
});

it('los vehículos dados de baja en flota no salen, para ningún alcance ni con since', function (): void {
    $active = flhTruck(Department::SantaCruz);
    $retired = flhTruck(Department::SantaCruz, 'inactive');
    // En mantenimiento sigue siendo parte de la flota en vivo.
    $maintenance = flhTruck(Department::SantaCruz, 'maintenance');
    $expected = collect([$active, $maintenance])->sort()->values()->all();
    $past = rawurlencode((new DateTimeImmutable('-1 hour'))->format(DATE_ATOM));

    // Sin usuario (procesos internos, pruebas sin autenticación) no hay alcance que aplicar, pero la baja
    // se excluye igual.
    expect(collect($this->getJson(FLH_URL)->assertOk()->json('data'))->pluck('vehicle_id')->sort()->values()->all())
        ->toBe($expected);

    $admin = flhUser(Role::Admin);
    $planner = flhUser(Role::Planificador, 'santa_cruz');

    expect(flhVisible($this, $admin))->toBe($expected)
        ->and(flhVisible($this, $admin))->not->toContain($retired)
        ->and(flhVisible($this, $admin, '?since='.$past))->toBe($expected)
        ->and(flhVisible($this, $planner))->toBe($expected);

    // Al reactivarlo en flota vuelve a salir.
    DB::table('fleet_vehicles')->where('id', $retired)->update(['status' => 'available']);
    expect(flhVisible($this, $admin))->toContain($retired);
});

it('el resolver de placas no guarda nada en el proceso: su estado vive en la caché corta', function (): void {
    config(['logistics-telemetry.live.reference_ttl_seconds' => 60]);
    $node = NodeFactory::new()->inDepartment(Department::SantaCruz)->create();
    $vehicle = VehicleFactory::new()->homeBase((string) $node->getKey())->create(['plate' => '1111AAA']);
    $vehicleId = (string) $vehicle->getKey();

    $resolver = new FleetPlateResolver;

    expect($resolver->label($vehicleId))->toBe('1111AAA')
        ->and($resolver->homeBaseNode($vehicleId))->toBe((string) $node->getKey());

    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['plate' => '2222BBB']);

    // Dentro de la ventana, una instancia NUEVA responde lo mismo: el dato sale del store, no del objeto.
    expect((new FleetPlateResolver)->label($vehicleId))->toBe('1111AAA');

    // Vencida la caché, la MISMA instancia (un worker persistente) ya ve la placa nueva.
    $this->travel(61)->seconds();
    expect($resolver->label($vehicleId))->toBe('2222BBB');

    // Y al olvidarla a mano, también sin esperar.
    DB::table('fleet_vehicles')->where('id', $vehicleId)->update(['plate' => '3333CCC']);
    $resolver->forget($vehicleId);
    expect($resolver->label($vehicleId))->toBe('3333CCC');

    // Lo inexistente y lo que no tiene forma de UUID degradan a null sin romper.
    expect($resolver->labels(['0192aaaa-bbbb-7ccc-8ddd-0000000000ff', 'veh-1']))
        ->toBe(['0192aaaa-bbbb-7ccc-8ddd-0000000000ff' => null, 'veh-1' => null])
        ->and(Cache::store('array')->get('telemetry:live:ref:plate:'.$vehicleId))
        ->toBe(['plate' => '3333CCC', 'node' => (string) $node->getKey()]);
});
