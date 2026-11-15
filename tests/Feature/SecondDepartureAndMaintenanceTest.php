<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Logistics\Execution\Application\Command\AttachShipmentToRoute\AttachShipmentToRoute;
use Logistics\Fleet\Database\Factories\VehicleFactory;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// (1) Un camión puede salir MÁS de una vez el mismo día: cada salida tiene su propia ruta (otro id).
// (2) Mandar un camión a mantenimiento resuelve su ruta: las paradas pendientes pasan a otro camión o quedan
//     por asignar; nunca quedan colgadas en un camión que está en el taller.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Deja al vehículo con una ruta de hoy en el estado dado y una parada por envío. Devuelve [routeId, shipmentIds].
 *
 * @param  list<string>                      $stopStatuses
 * @return array{0: string, 1: list<string>}
 */
function sdmRoute(string $vehicleId, string $routeStatus, array $stopStatuses): array
{
    $routeId = (string) Str::uuid7();
    DB::table('execution_driver_routes')->insert([
        'id' => $routeId,
        'vehicle_id' => $vehicleId,
        'route_date' => now()->toDateString(),
        'status' => $routeStatus,
        'created_at' => now()->subHours(3),
        'updated_at' => now()->subHours(3),
    ]);

    $shipments = [];
    foreach ($stopStatuses as $i => $status) {
        $shipment = ShipmentRecord::factory()->create(['external_id' => 'PED-SDM-'.Str::random(6)]);
        $shipments[] = (string) $shipment->id;
        DB::table('execution_driver_route_stops')->insert([
            'id' => (string) Str::uuid7(),
            'route_id' => $routeId,
            'shipment_id' => $shipment->id,
            'sequence' => $i + 1,
            'status' => $status,
            'order_reference' => $shipment->external_id,
            'customer_name' => $shipment->customer_name,
            'address' => $shipment->address_text,
        ]);
    }

    return [$routeId, $shipments];
}

it('el camión sale por segunda vez el mismo día con una ruta nueva', function (): void {
    $vehicleId = (string) Str::uuid7();
    [$firstRoute] = sdmRoute($vehicleId, 'COMPLETED', ['COMPLETED']);

    $shipment = ShipmentRecord::factory()->create();
    app(CommandBus::class)->dispatch(new AttachShipmentToRoute($vehicleId, (string) $shipment->id, 'PED-2DA', 'Cliente', 'Dirección'));

    $routes = DB::table('execution_driver_routes')->where('vehicle_id', $vehicleId)->where('route_date', now()->toDateString())->pluck('status', 'id')->all();

    expect($routes)->toHaveCount(2)
        ->and($routes[$firstRoute])->toBe('COMPLETED')
        ->and(array_values(array_diff($routes, ['COMPLETED'])))->toBe(['PLANNED']);
});

it('no permite dos rutas ABIERTAS del mismo camión el mismo día', function (): void {
    $vehicleId = (string) Str::uuid7();
    sdmRoute($vehicleId, 'IN_PROGRESS', ['PENDING']);

    expect(fn () => DB::table('execution_driver_routes')->insert([
        'id' => (string) Str::uuid7(),
        'vehicle_id' => $vehicleId,
        'route_date' => now()->toDateString(),
        'status' => 'PLANNED',
    ]))->toThrow(QueryException::class);
});

it('al mandar el camión a mantenimiento sus paradas pendientes pasan al camión indicado', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    Sanctum::actingAs($admin);

    $broken = VehicleFactory::new()->create(['status' => 'available']);
    $spare = VehicleFactory::new()->create(['status' => 'available']);
    [, $shipments] = sdmRoute((string) $broken->id, 'IN_PROGRESS', ['COMPLETED', 'PENDING', 'PENDING']);

    $this->postJson('/api/v1/app/fleet/vehicles/'.$broken->id.'/send-to-maintenance', [
        'note' => 'Falla de frenos',
        'reassign_to_vehicle_id' => (string) $spare->id,
    ])
        ->assertOk()
        ->assertJsonPath('data.new_status', 'maintenance')
        ->assertJsonPath('data.route.pending_stops', 2)
        ->assertJsonPath('data.route.reassigned_to_vehicle_id', (string) $spare->id)
        ->assertJsonCount(2, 'data.route.reassigned')
        ->assertJsonPath('data.route.pending_reassignment', []);

    $onSpare = DB::table('execution_driver_route_stops as s')
        ->join('execution_driver_routes as r', 'r.id', '=', 's.route_id')
        ->where('r.vehicle_id', $spare->id)
        ->pluck('s.shipment_id')->all();

    expect($onSpare)->toHaveCount(2)
        ->and($onSpare)->toContain($shipments[1], $shipments[2])
        ->and(DB::table('execution_deliveries')->whereIn('shipment_id', [$shipments[1], $shipments[2]])->pluck('vehicle_id')->unique()->all())->toBe([(string) $spare->id])
        ->and(VehicleModel::query()->findOrFail($broken->id)->status)->toBe('maintenance');
});

it('sin camión destino las paradas pendientes se liberan y se informan para reasignar', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole(Role::Supervisor);
    Sanctum::actingAs($admin);

    $broken = VehicleFactory::new()->create(['status' => 'available']);
    [$routeId, $shipments] = sdmRoute((string) $broken->id, 'IN_PROGRESS', ['COMPLETED', 'PENDING']);

    $this->postJson('/api/v1/app/fleet/vehicles/'.$broken->id.'/send-to-maintenance')
        ->assertOk()
        ->assertJsonPath('data.route.pending_stops', 1)
        ->assertJsonPath('data.route.reassigned', [])
        ->assertJsonPath('data.route.pending_reassignment.0.shipment_id', $shipments[1]);

    // La parada ya atendida se conserva; la pendiente salió de la ruta del camión.
    expect(DB::table('execution_driver_route_stops')->where('route_id', $routeId)->pluck('shipment_id')->all())->toBe([$shipments[0]]);
});
