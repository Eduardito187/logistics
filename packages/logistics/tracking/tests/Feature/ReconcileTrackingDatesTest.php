<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Persistence\Eloquent\TrackingRecord;

// `logistics:tracking:reconcile-dates`: marca la llegada a la tienda de pedidos que ya estaban ahí y corrige
// las fechas de entrega guardadas con el corrimiento del desplazamiento horario. Simulación por defecto.
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

function rtdNode(string $type = 'store'): string
{
    $id = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $id,
        'code' => 'RTD-'.Str::upper(Str::random(6)),
        'name' => 'Nodo de prueba',
        'type' => $type,
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
        'department' => 'santa_cruz',
    ]);

    return $id;
}

/**
 * Entrega de execution para el envío, insertada directo (solo las columnas que el comando lee).
 *
 * @param array<string, mixed> $attributes
 */
function rtdDelivery(string $shipmentId, array $attributes): void
{
    DB::table('execution_deliveries')->insert($attributes + [
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'vehicle_id' => (string) Str::uuid7(),
        'status' => 'LOADED',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('marca la llegada a la tienda solo para retiro en tienda en su tienda destino', function (): void {
    $store = rtdNode();
    $otherStore = rtdNode();

    $pickup = ShipmentRecord::factory()->create(['source' => 'MAGENTO', 'destination_node_id' => $store]);
    $elsewhere = ShipmentRecord::factory()->create(['source' => 'MAGENTO', 'destination_node_id' => $store]);
    $transfer = ShipmentRecord::factory()->create(['source' => 'TRANSFER', 'destination_node_id' => $store]);

    foreach ([$pickup, $elsewhere, $transfer] as $shipment) {
        TrackingRecord::factory()->withStatus(VisibleStatus::Loaded)->create(['shipment_id' => $shipment->id]);
    }

    rtdDelivery($pickup->id, ['store_dropped_at' => '2026-11-10T15:00:00+00:00', 'store_drop_node_id' => $store]);
    rtdDelivery($elsewhere->id, ['store_dropped_at' => '2026-11-10T15:00:00+00:00', 'store_drop_node_id' => $otherStore]);
    rtdDelivery($transfer->id, ['store_dropped_at' => '2026-11-10T15:00:00+00:00', 'store_drop_node_id' => $store]);

    $atStore = static fn (string $shipmentId): mixed => TrackingRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->at_store_at;

    // Simulación: no escribe.
    $this->artisan('logistics:tracking:reconcile-dates')->assertSuccessful();
    expect($atStore($pickup->id))->toBeNull();

    $this->artisan('logistics:tracking:reconcile-dates', ['--force' => true])->assertSuccessful();

    expect($atStore($pickup->id)?->getTimestamp())->toBe(strtotime('2026-11-10T15:00:00+00:00'))
        ->and($atStore($elsewhere->id))->toBeNull()
        ->and($atStore($transfer->id))->toBeNull();

    // Idempotente.
    $this->artisan('logistics:tracking:reconcile-dates', ['--force' => true])->assertSuccessful();
    expect($atStore($pickup->id)?->getTimestamp())->toBe(strtotime('2026-11-10T15:00:00+00:00'));
});

it('corrige solo las fechas de entrega corridas exactamente el desplazamiento horario', function (): void {
    $shift = -1 * (new DateTimeZone((string) config('app.timezone')))->getOffset(new DateTimeImmutable('now'));
    if ($shift === 0) {
        $this->markTestSkipped('La zona de la app es UTC: no hay corrimiento que corregir.');
    }

    $real = strtotime('2026-11-05T14:00:00+00:00');
    $iso = static fn (int $epoch): string => gmdate('Y-m-d\TH:i:s+00:00', $epoch);

    $shifted = (string) Str::uuid7();
    $correct = (string) Str::uuid7();
    $other = (string) Str::uuid7();

    TrackingRecord::factory()->delivered()->create(['shipment_id' => $shifted, 'delivered_at' => $iso($real + $shift)]);
    TrackingRecord::factory()->delivered()->create(['shipment_id' => $correct, 'delivered_at' => $iso($real)]);
    // Diferencia que NO es el corrimiento (p. ej. se sincronizó una hora después): no se toca.
    TrackingRecord::factory()->delivered()->create(['shipment_id' => $other, 'delivered_at' => $iso($real + 3600)]);

    foreach ([$shifted, $correct, $other] as $shipmentId) {
        rtdDelivery($shipmentId, ['status' => 'DELIVERED', 'pod_captured_at' => $iso($real)]);
    }

    $deliveredAt = static fn (string $shipmentId): ?int => TrackingRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->delivered_at?->getTimestamp();

    $this->artisan('logistics:tracking:reconcile-dates')->assertSuccessful();
    expect($deliveredAt($shifted))->toBe($real + $shift);

    $this->artisan('logistics:tracking:reconcile-dates', ['--force' => true])->assertSuccessful();

    expect($deliveredAt($shifted))->toBe($real)
        ->and($deliveredAt($correct))->toBe($real)
        ->and($deliveredAt($other))->toBe($real + 3600);
});
