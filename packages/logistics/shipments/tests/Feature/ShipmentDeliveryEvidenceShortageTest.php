<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Query\FindShipment\FindShipment;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// La ficha del envío arma los tags de «cómo se entregó» desde `delivery_evidence`. Además de firma/foto/
// documento, expone si el envío se cerró CON FALTANTE (`with_shortage`) y cuántos productos faltaron
// (`missing_count`), leídos de la misma fila de ejecución que el resto de la evidencia. La lista de productos
// faltantes NO se expone acá: se ve por el reporte de discrepancias.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

// Estado y confirmación reutilizados en los casos (literal repetido → const con prefijo del archivo).
const SDES_DELIVERED = 'DELIVERED';
const SDES_STORE_DROP = 'STORE_DROP';

/**
 * Inserta la fila de ejecución de un envío entregado, con el cierre dado.
 *
 * @param list<string> $missingUnitIds productos declarados como faltantes (para `store_drop_missing_units`)
 */
function sdesDelivery(string $shipmentId, string $confirmation, bool $withShortage = false, array $missingUnitIds = []): void
{
    $missing = null;
    if ($missingUnitIds !== []) {
        $missing = json_encode(array_map(
            static fn (string $unitId): array => ['unit_id' => $unitId, 'reason' => 'damaged'],
            $missingUnitIds,
        ));
    }

    DB::table('execution_deliveries')->insert([
        'id' => (string) Str::uuid7(),
        'shipment_id' => $shipmentId,
        'vehicle_id' => (string) Str::uuid7(),
        'status' => SDES_DELIVERED,
        'pod_confirmation' => $confirmation,
        'pod_captured_at' => '2026-11-14 10:00:00-04',
        'closed_with_shortage' => $withShortage,
        'store_drop_missing_units' => $missing,
    ]);
}

/**
 * @return array{signature: bool, photo: bool, document: bool, with_shortage: bool, missing_count: int | null}|null
 */
function sdesEvidence(string $shipmentId): ?array
{
    $view = app(QueryBus::class)->ask(new FindShipment($shipmentId, withDeliveryEvidence: true));

    return $view?->deliveryEvidence?->toArray();
}

it('expone with_shortage=true y missing_count cuando el envío se cerró con faltante', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'PED-SDES-SHORT', 'status' => SDES_DELIVERED]);
    sdesDelivery($shipment->id, 'QR-123', withShortage: true, missingUnitIds: ['u-1', 'u-2']);

    expect(sdesEvidence($shipment->id))->toMatchArray([
        'with_shortage' => true,
        'missing_count' => 2,
    ]);
});

it('expone with_shortage=false y missing_count null en una entrega normal', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'PED-SDES-OK', 'status' => SDES_DELIVERED]);
    sdesDelivery($shipment->id, 'QR-456');

    expect(sdesEvidence($shipment->id))->toMatchArray([
        'with_shortage' => false,
        'missing_count' => null,
    ]);
});

it('expone el faltante también en el store-drop de un traspaso cerrado con faltante', function (): void {
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'PED-SDES-TRANSFER', 'status' => SDES_DELIVERED]);
    sdesDelivery($shipment->id, SDES_STORE_DROP, withShortage: true, missingUnitIds: ['u-9']);

    expect(sdesEvidence($shipment->id))->toMatchArray([
        'with_shortage' => true,
        'missing_count' => 1,
    ]);
});

it('entrega sin productos faltantes declarados informa missing_count null aunque el cierre venga con faltante', function (): void {
    // Conciliación con diferencias donde la tienda reportó los faltantes (no quedan en store_drop_missing_units):
    // `with_shortage` se respeta, pero sin cantidad conocida se informa null (missing_count es opcional).
    $shipment = ShipmentRecord::factory()->create(['external_id' => 'PED-SDES-NOCOUNT', 'status' => SDES_DELIVERED]);
    sdesDelivery($shipment->id, SDES_STORE_DROP, withShortage: true);

    expect(sdesEvidence($shipment->id))->toMatchArray([
        'with_shortage' => true,
        'missing_count' => null,
    ]);
});
