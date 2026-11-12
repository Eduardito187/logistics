<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Http\Middleware\EnsureDeviceToken;
use App\Http\Middleware\IdempotentRequests;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\CarrierFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\SettlementFactory;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Factory\TariffFactory;

/**
 * Brechas de auditoría del contexto carriers:
 *   - GET /app/carriers/corridors: corredores distintos desde las tarifas, con clases de vehículo y
 *     etiquetas legibles; `from`/`to` son el slug exacto de la tarifa. Alcance por EnsureCarrierScope.
 *   - GET /app/carriers/settlements?period_from=&period_to= (Y-m-d inclusivos, por mes de facturación).
 *   - Settlement.vehicle_class: explícita al enviar, o derivada de la tarifa única del corredor.
 *
 * Se desactivan solo la guarda de dispositivo e idempotencia (auth:sanctum y el alcance quedan activos).
 * Contra PostgreSQL (logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware([EnsureDeviceToken::class, IdempotentRequests::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function carrierCorridorsActingAs(Role $role, ?string $carrierId = null): User
{
    $user = User::factory()->create();
    $user->assignRole($role);
    $user->forceFill(['carrier_id' => $carrierId])->save();
    test()->actingAs($user, 'sanctum');

    return $user;
}

function carrierCorridorsTariff(string $carrierId, string $from, string $to, string $type, string $class): void
{
    TariffFactory::new()
        ->forCarrier($carrierId)
        ->forCorridor($from, $to, $type)
        ->forVehicleClass($class)
        ->priced(100_000)
        ->create();
}

it('el transportista solo ve los corredores de SUS tarifas e ignora el carrier_id del cliente', function (): void {
    $mine = CarrierFactory::new()->create();
    $other = CarrierFactory::new()->create();
    carrierCorridorsTariff($mine->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'trailer');
    carrierCorridorsTariff($mine->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'truck');
    carrierCorridorsTariff($mine->id, 'cd-santa-cruz', 'store-scz-centro', 'cd_to_store', 'truck');
    carrierCorridorsTariff($other->id, 'cd-la-paz', 'cd-cochabamba', 'cd_to_cd', 'trailer');

    carrierCorridorsActingAs(Role::Transportista, $mine->id);

    $response = $this->getJson("/api/v1/app/carriers/corridors?carrier_id={$other->id}")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Orden: tipo, origen, destino. `from`/`to` son el slug EXACTO de la tarifa.
    $response
        ->assertJsonPath('data.0.from', 'cd-santa-cruz')
        ->assertJsonPath('data.0.to', 'cd-la-paz')
        ->assertJsonPath('data.0.corridor_type', 'cd_to_cd')
        ->assertJsonPath('data.0.vehicle_classes', ['trailer', 'truck'])
        ->assertJsonPath('data.1.to', 'store-scz-centro')
        ->assertJsonPath('data.1.vehicle_classes', ['truck']);

    expect(collect($response->json('data'))->pluck('to')->all())->not->toContain('cd-cochabamba');
});

it('la oficina ve los corredores de todos los transportistas y filtra por carrier_id', function (): void {
    $a = CarrierFactory::new()->create();
    $b = CarrierFactory::new()->create();
    carrierCorridorsTariff($a->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'trailer');
    // El mismo corredor tarifado por otro transportista con otra clase: un solo corredor, clases unidas.
    carrierCorridorsTariff($b->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'third_party');
    carrierCorridorsTariff($b->id, 'cd-la-paz', 'cd-cochabamba', 'cd_to_cd', 'trailer');

    carrierCorridorsActingAs(Role::Admin);

    $this->getJson('/api/v1/app/carriers/corridors')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.from', 'cd-la-paz')
        ->assertJsonPath('data.1.from', 'cd-santa-cruz')
        ->assertJsonPath('data.1.vehicle_classes', ['third_party', 'trailer']);

    $this->getJson("/api/v1/app/carriers/corridors?carrier_id={$a->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.vehicle_classes', ['trailer']);
});

it('etiqueta los extremos con el nombre del nodo si hay mapeo y si no humaniza el slug', function (): void {
    $carrier = CarrierFactory::new()->create();
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'store-scz-centro', 'cd_to_store', 'truck');
    carrierCorridorsTariff($carrier->id, 'CD-LPZ-91', 'cd-la-paz', 'cd_to_cd', 'trailer');

    // Nodo cuyo código coincide (sin distinguir mayúsculas) con un extremo de la tarifa.
    DB::table('network_nodes')->insert([
        'id' => Str::uuid7()->toString(),
        'code' => 'cd-lpz-91',
        'name' => 'Distribution Center El Alto',
        'type' => 'cd',
        'lat' => -16.5,
        'lng' => -68.15,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    carrierCorridorsActingAs(Role::Transportista, $carrier->id);

    $this->getJson('/api/v1/app/carriers/corridors')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.from', 'CD-LPZ-91')
        ->assertJsonPath('data.0.from_label', 'Distribution Center El Alto')
        ->assertJsonPath('data.0.to_label', 'CD La Paz')
        ->assertJsonPath('data.1.from_label', 'CD Santa Cruz')
        ->assertJsonPath('data.1.to_label', 'Store SCZ Centro');
});

it('pagina los corredores solo si se pide (opt-in) con el envelope estándar', function (): void {
    $carrier = CarrierFactory::new()->create();
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'trailer');
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'cd-cochabamba', 'cd_to_cd', 'trailer');
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'store-scz-centro', 'cd_to_store', 'truck');

    carrierCorridorsActingAs(Role::Supervisor);

    $this->getJson('/api/v1/app/carriers/corridors')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonMissingPath('meta');

    $this->getJson('/api/v1/app/carriers/corridors?per_page=2&page=2')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.total', 3)
        ->assertJsonPath('meta.total_pages', 2);
});

it('un rol que no es oficina ni transportista recibe 403 en corredores', function (): void {
    carrierCorridorsActingAs(Role::Chofer);

    $this->getJson('/api/v1/app/carriers/corridors')->assertForbidden();
});

it('filtra las liquidaciones por rango de período (Y-m-d inclusivo, por mes de facturación)', function (): void {
    $carrier = CarrierFactory::new()->create();
    foreach (['2026-08', '2026-09', '2026-10', '2026-11'] as $period) {
        SettlementFactory::new()->forCarrier($carrier->id)
            ->forCorridorState('cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', $period, 100_000)
            ->create();
    }

    carrierCorridorsActingAs(Role::Transportista, $carrier->id);

    $response = $this->getJson('/api/v1/app/carriers/settlements?period_from=2026-09-15&period_to=2026-10-01')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.total', 2);
    expect(collect($response->json('data'))->pluck('period')->all())->toBe(['2026-10', '2026-09']);

    // Solo cota inferior.
    $this->getJson('/api/v1/app/carriers/settlements?period_from=2026-10-31')
        ->assertOk()
        ->assertJsonCount(2, 'data');

    // Solo cota superior, con paginación.
    $this->getJson('/api/v1/app/carriers/settlements?period_to=2026-08-01&per_page=10')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.period', '2026-08');
});

it('rechaza un período mal formado o invertido (422)', function (): void {
    $carrier = CarrierFactory::new()->create();

    carrierCorridorsActingAs(Role::Transportista, $carrier->id);

    $this->getJson('/api/v1/app/carriers/settlements?period_from=2026-10')->assertStatus(422);
    $this->getJson('/api/v1/app/carriers/settlements?period_from=2026-10-10&period_to=2026-10-01')
        ->assertStatus(422);
});

it('persiste la clase de vehículo de la liquidación: explícita o derivada de la tarifa única', function (): void {
    $carrier = CarrierFactory::new()->create();
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'store-scz-centro', 'cd_to_store', 'truck');
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'trailer');
    carrierCorridorsTariff($carrier->id, 'cd-santa-cruz', 'cd-la-paz', 'cd_to_cd', 'truck');

    $submit = fn (string $to, string $type, string $reference, ?string $class = null) => $this->withoutMiddleware()
        ->postJson("/api/v1/integrations/carriers/{$carrier->id}/settlements", array_filter([
            'corridor_from' => 'cd-santa-cruz',
            'corridor_to' => $to,
            'corridor_type' => $type,
            'period' => '2026-10',
            'amount_cents' => 250_000,
            'currency' => 'BOB',
            'reference' => $reference,
            'vehicle_class' => $class,
        ], static fn (mixed $value): bool => $value !== null))
        ->assertStatus(202);

    // Una sola clase tarifada en el corredor → se deriva.
    $submit('store-scz-centro', 'cd_to_store', 'FAC-DERIVED');
    // Varias clases y sin explícita → null (no se adivina).
    $submit('cd-la-paz', 'cd_to_cd', 'FAC-AMBIGUOUS');
    // Explícita → se respeta.
    $submit('cd-la-paz', 'cd_to_cd', 'FAC-EXPLICIT', 'truck');

    $classOf = static fn (string $reference): mixed => DB::table('carrier_settlements')
        ->where('reference', $reference)
        ->value('vehicle_class');

    expect($classOf('FAC-DERIVED'))->toBe('truck')
        ->and($classOf('FAC-AMBIGUOUS'))->toBeNull()
        ->and($classOf('FAC-EXPLICIT'))->toBe('truck');

    $id = DB::table('carrier_settlements')->where('reference', 'FAC-DERIVED')->value('id');

    carrierCorridorsActingAs(Role::Admin);

    $this->getJson("/api/v1/app/carriers/settlements/{$id}")
        ->assertOk()
        ->assertJsonPath('data.vehicle_class', 'truck');
});

it('rechaza una clase de vehículo inválida al enviar la liquidación (422)', function (): void {
    $carrier = CarrierFactory::new()->create();

    $this->withoutMiddleware()
        ->postJson("/api/v1/integrations/carriers/{$carrier->id}/settlements", [
            'corridor_from' => 'cd-santa-cruz',
            'corridor_to' => 'cd-la-paz',
            'corridor_type' => 'cd_to_cd',
            'period' => '2026-10',
            'amount_cents' => 250_000,
            'currency' => 'BOB',
            'reference' => 'FAC-BAD-CLASS',
            'vehicle_class' => 'bicycle',
        ])
        ->assertStatus(422);
});
