<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;

// Ciudad (departamento) de cada persona de la app: se expone en la sesión y en la ficha del personal, se
// filtra en la lista, se edita respetando la regla "quien tiene nodo es de la ciudad de su nodo" y se
// completa para lo existente con `logistics:staff:backfill-city`.
beforeEach(function (): void {
    $this->withoutMiddleware(EnsureDeviceToken::class);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function sctActingAs(Role $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole($role);
    Sanctum::actingAs($user);

    return $user;
}

function sctNode(string $department, string $type = 'store'): string
{
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'SC-'.Str::upper(Str::random(8)),
        'name' => 'Nodo '.$department,
        'type' => $type,
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'department' => $department,
    ]);

    return $id;
}

function sctVehicle(string $baseNodeId): string
{
    $id = (string) Str::uuid7();
    DB::table('fleet_vehicles')->insert([
        'id' => $id,
        'plate' => 'SC'.Str::upper(Str::random(6)),
        'capacity_kg' => 1000,
        'capacity_m3' => 10,
        'home_base_node_id' => $baseNodeId,
        'status' => 'available',
        'documents' => '[]',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function sctCrew(string $driverId, string $vehicleId, string $date): void
{
    DB::table('fleet_crews')->insert([
        'id' => (string) Str::uuid7(),
        'driver_id' => $driverId,
        'helper_ids' => '[]',
        'vehicle_id' => $vehicleId,
        'assembled_for' => $date,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('devuelve la ciudad de la persona en su sesión', function (): void {
    sctActingAs(Role::Preparador, ['city' => 'potosi']);

    $this->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.city', 'potosi')
        ->assertJsonPath('user.city_name', 'Potosí');

    sctActingAs(Role::Chofer);

    $this->getJson('/api/v1/app/auth/me')
        ->assertOk()
        ->assertJsonPath('user.city', null)
        ->assertJsonPath('user.city_name', null);
});

it('muestra la ciudad en la lista y la ficha, y filtra por ciudad y por sin ciudad', function (): void {
    sctActingAs(Role::Admin, ['name' => 'Admin Pruebas', 'city' => 'santa_cruz']);

    $paceno = User::factory()->create(['name' => 'Yago Paceño', 'city' => 'la_paz']);
    $paceno->assignRole(Role::Preparador);
    $sinCiudad = User::factory()->create(['name' => 'Yara Sin Ciudad']);
    $sinCiudad->assignRole(Role::Chofer);

    $this->getJson('/api/v1/app/staff?city=la_paz')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $paceno->id)
        ->assertJsonPath('data.0.city', 'la_paz')
        ->assertJsonPath('data.0.city_name', 'La Paz');

    $this->getJson('/api/v1/app/staff?without_city=true')
        ->assertOk()
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.id', $sinCiudad->id)
        ->assertJsonPath('data.0.city', null)
        ->assertJsonPath('data.0.city_name', null);

    $this->getJson('/api/v1/app/staff?city=atlantida')->assertStatus(422);

    $this->getJson('/api/v1/app/staff/'.$paceno->id)
        ->assertOk()
        ->assertJsonPath('data.city', 'la_paz')
        ->assertJsonPath('data.city_name', 'La Paz');
});

it('al asignar un nodo la ciudad pasa a ser la del nodo, y al quitarlo se conserva', function (): void {
    sctActingAs(Role::Admin);
    $store = sctNode('cochabamba');
    $person = User::factory()->create(['city' => 'la_paz']);

    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => $store])
        ->assertOk()
        ->assertJsonPath('data.node.id', $store)
        ->assertJsonPath('data.city', 'cochabamba')
        ->assertJsonPath('data.city_name', 'Cochabamba');

    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => null])
        ->assertOk()
        ->assertJsonPath('data.node', null)
        ->assertJsonPath('data.city', 'cochabamba');

    // Un nodo con el departamento escrito como nombre (dato viejo) da igualmente el código.
    $legacy = sctNode('Santa Cruz');
    $this->putJson('/api/v1/app/staff/'.$person->id.'/node', ['node_id' => $legacy])
        ->assertOk()
        ->assertJsonPath('data.city', 'santa_cruz');
});

it('edita la ciudad de quien no tiene nodo y rechaza una distinta a la de su nodo', function (): void {
    sctActingAs(Role::Supervisor);
    $free = User::factory()->create();

    $this->putJson('/api/v1/app/staff/'.$free->id.'/profile', ['city' => 'tarija', 'phone' => '70011223'])
        ->assertOk()
        ->assertJsonPath('data.city', 'tarija')
        ->assertJsonPath('data.city_name', 'Tarija')
        ->assertJsonPath('data.phone', '70011223');

    // Si `city` no viaja, no se toca; con null se quita.
    $this->putJson('/api/v1/app/staff/'.$free->id.'/profile', ['phone' => '70099887'])
        ->assertOk()
        ->assertJsonPath('data.city', 'tarija');
    $this->putJson('/api/v1/app/staff/'.$free->id.'/profile', ['city' => null])
        ->assertOk()
        ->assertJsonPath('data.city', null);

    $this->putJson('/api/v1/app/staff/'.$free->id.'/profile', ['city' => 'atlantida'])->assertStatus(422);

    $store = sctNode('santa_cruz');
    $clerk = User::factory()->create(['node_id' => $store, 'city' => 'santa_cruz']);

    $rejected = $this->putJson('/api/v1/app/staff/'.$clerk->id.'/profile', ['city' => 'la_paz', 'phone' => '70000001'])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed');

    // El motivo viaja en `detail` y en `error.message` (contrato único de error).
    expect($rejected->json('detail'))->toBeString()->toContain('Santa Cruz')
        ->and($rejected->json('error.message'))->toBe($rejected->json('detail'));

    // Nada del pedido rechazado quedó guardado.
    expect(DB::table('users')->where('id', $clerk->id)->value('city'))->toBe('santa_cruz')
        ->and(DB::table('staff_profiles')->where('user_id', $clerk->id)->exists())->toBeFalse();

    // Tampoco se puede dejar sin ciudad a quien tiene nodo; la misma ciudad del nodo sí se acepta.
    $this->putJson('/api/v1/app/staff/'.$clerk->id.'/profile', ['city' => null])->assertStatus(422);
    $this->putJson('/api/v1/app/staff/'.$clerk->id.'/profile', ['city' => 'santa_cruz'])
        ->assertOk()
        ->assertJsonPath('data.city', 'santa_cruz');
});

it('sincroniza la ciudad al asignar la tienda desde el admin web y al cambiar el departamento del nodo', function (): void {
    $store = sctNode('cochabamba');

    // Asignación por Eloquent (así guarda Filament): la ciudad pasa a ser la del nodo.
    $clerk = User::factory()->create(['city' => 'la_paz']);
    $clerk->update(['node_id' => $store]);
    expect($clerk->refresh()->city)->toBe('cochabamba');

    // Quitar el nodo conserva la ciudad.
    $clerk->update(['node_id' => null]);
    expect($clerk->refresh()->city)->toBe('cochabamba');

    // Cambiar el departamento del nodo mueve a su personal; el de otros nodos no se toca.
    $clerk->update(['node_id' => $store]);
    $other = User::factory()->create(['node_id' => sctNode('tarija')]);

    NodeRecord::query()->findOrFail($store)->update(['department' => 'oruro']);

    expect($clerk->refresh()->city)->toBe('oruro')
        ->and($other->refresh()->city)->toBe('tarija');

    // Un nodo que queda sin departamento no le quita la ciudad a nadie.
    NodeRecord::query()->findOrFail($store)->update(['department' => null]);
    expect($clerk->refresh()->city)->toBe('oruro');
});

it('completa la ciudad faltante desde el nodo o el vehículo, sin pisar la ya cargada', function (): void {
    $store = sctNode('la_paz');
    $base = sctNode('cochabamba', 'distribution_center');
    $otherBase = sctNode('oruro', 'distribution_center');

    // Datos anteriores a la regla: el nodo se fija directo en la tabla (guardar el modelo ya sincroniza la ciudad).
    $clerk = User::factory()->create();
    DB::table('users')->where('id', $clerk->id)->update(['node_id' => $store]);

    // Chofer con dos tripulaciones: manda la más reciente (base Cochabamba).
    $driverId = (string) Str::uuid7();
    $driver = User::factory()->create(['driver_id' => $driverId]);
    sctCrew($driverId, sctVehicle($otherBase), now()->subDays(5)->toDateString());
    sctCrew($driverId, sctVehicle($base), now()->subDay()->toDateString());

    $alreadySet = User::factory()->create(['city' => 'tarija']);
    DB::table('users')->where('id', $alreadySet->id)->update(['node_id' => $store]);
    $unknown = User::factory()->create();

    $city = static fn (User $user): mixed => DB::table('users')->where('id', $user->id)->value('city');

    // Simulación: no escribe nada.
    $this->artisan('logistics:staff:backfill-city')->assertExitCode(0);
    expect($city($clerk))->toBeNull()->and($city($driver))->toBeNull();

    $this->artisan('logistics:staff:backfill-city', ['--force' => true])->assertExitCode(0);

    expect($city($clerk))->toBe('la_paz')
        ->and($city($driver))->toBe('cochabamba')
        ->and($city($alreadySet))->toBe('tarija')
        ->and($city($unknown))->toBeNull();

    // Idempotente: una segunda corrida no cambia nada.
    $this->artisan('logistics:staff:backfill-city', ['--force' => true])->assertExitCode(0);
    expect($city($clerk))->toBe('la_paz')->and($city($driver))->toBe('cochabamba')->and($city($unknown))->toBeNull();
});
