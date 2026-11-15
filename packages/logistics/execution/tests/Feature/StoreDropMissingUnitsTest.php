<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Logistics\Execution\Application\Command\RecordStoreDrop\RecordStoreDrop;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// TIENDA SIN RECEPCIÓN: la tienda ya no recibe ni concilia mercadería, así que los FALTANTES los declara el
// chofer en su entrega en tienda (`missing_units`: producto + motivo). El pedido queda igual «listo para
// retirar»; el faltante queda en la entrega, en el hecho del outbox y en la lectura de la entrega.
beforeEach(function (): void {
    if (!Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')) {
        test()->markTestSkipped('execution_deliveries.store_drop_missing_units todavía no existe (migración pendiente).');
    }

    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Pedido con retiro en tienda, cargado en un camión, con $units productos. Devuelve [shipmentId, storeId, unitIds].
 *
 * @return array{0: string, 1: string, 2: list<string>}
 */
function sdmPickupWithUnits(int $units): array
{
    $storeId = (string) Str::uuid7();
    DB::table('network_nodes')->insert([
        'id' => $storeId,
        'code' => 'T-'.Str::upper(Str::random(6)),
        'name' => 'Tienda de prueba',
        'type' => 'store',
        'lat' => -17.78,
        'lng' => -63.18,
        'status' => 'active',
    ]);

    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'PED-SDM-'.Str::random(6),
        'destination_node_id' => $storeId,
        'source' => 'MAGENTO',
        'status' => 'IN_EXECUTION',
    ]);
    $shipmentId = (string) $shipment->id;

    $lineId = (string) Str::uuid7();
    DB::table('shipment_lines')->insert([
        'id' => $lineId,
        'shipment_id' => $shipmentId,
        'sku' => 'SKU-TV-55',
        'product_name' => 'Televisor LED 55',
        'quantity' => $units,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $unitIds = [];
    for ($i = 1; $i <= $units; $i++) {
        $unitId = (string) Str::uuid7();
        DB::table('shipment_units')->insert([
            'id' => $unitId,
            'shipment_id' => $shipmentId,
            'line_id' => $lineId,
            'unit_index' => $i,
            'unit_total' => $units,
            'piece_index' => 1,
            'piece_total' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $unitIds[] = $unitId;
    }

    DeliveryRecord::factory()->create(['shipment_id' => $shipmentId, 'vehicle_id' => (string) Str::uuid7(), 'status' => 'LOADED']);

    return [$shipmentId, $storeId, $unitIds];
}

it('guarda los productos que el chofer no pudo dejar y los publica en el hecho', function (): void {
    [$shipmentId, $storeId, $unitIds] = sdmPickupWithUnits(3);
    $missing = [['unit_id' => $unitIds[2], 'reason' => 'damaged']];

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00', null, $missing));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $event = DB::table('outbox_messages')
        ->where('event_name', 'execution.shipment_dropped_at_store')
        ->where('payload->shipment_id', $shipmentId)
        ->first();
    $view = app(QueryBus::class)->ask(new GetDeliveryState($shipmentId));

    expect($delivery->getAttribute('store_dropped_at'))->not->toBeNull()
        ->and($delivery->getAttribute('store_drop_missing_units'))->toEqual($missing)
        ->and(json_decode((string) $event?->payload, true)['missing_units'] ?? null)->toEqual($missing)
        ->and($view?->toArray()['store_drop_missing_units'] ?? null)->toEqual($missing);
});

it('sin faltantes la entrega en tienda queda completa (contrato de siempre)', function (): void {
    [$shipmentId, $storeId] = sdmPickupWithUnits(2);

    app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00'));

    $delivery = DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail();
    $view = app(QueryBus::class)->ask(new GetDeliveryState($shipmentId));

    expect($delivery->getAttribute('store_drop_missing_units'))->toBeNull()
        ->and($view?->toArray()['store_drop_missing_units'] ?? null)->toBe([]);
});

it('rechaza un faltante que no es un producto del pedido', function (): void {
    [$shipmentId, $storeId] = sdmPickupWithUnits(2);

    app(CommandBus::class)->dispatch(new RecordStoreDrop(
        $shipmentId,
        $storeId,
        '2026-11-14T11:00:00-04:00',
        null,
        [['unit_id' => (string) Str::uuid7(), 'reason' => 'not_arrived']],
    ));
})->throws(ExecutionException::class, 'no pertenece a este pedido');

it('rechaza declarar faltante el pedido entero: eso es una entrega fallida', function (): void {
    [$shipmentId, $storeId, $unitIds] = sdmPickupWithUnits(2);

    $all = array_map(static fn (string $unitId): array => ['unit_id' => $unitId, 'reason' => 'not_arrived'], $unitIds);

    expect(fn () => app(CommandBus::class)->dispatch(new RecordStoreDrop($shipmentId, $storeId, '2026-11-14T11:00:00-04:00', null, $all)))
        ->toThrow(ExecutionException::class, 'entrega como fallida');

    expect(DeliveryRecord::query()->where('shipment_id', $shipmentId)->firstOrFail()->getAttribute('store_dropped_at'))->toBeNull();
});

it('valida por HTTP el motivo del faltante (422)', function (): void {
    $this->postJson('/api/v1/app/deliveries/'.Str::uuid7().'/store-drop', [
        'missing_units' => [['unit_id' => (string) Str::uuid7(), 'reason' => 'se_perdio']],
    ])->assertStatus(422);
});
