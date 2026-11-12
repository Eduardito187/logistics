<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\MarkShipmentDeparted\MarkShipmentDeparted;
use Logistics\Execution\Application\Command\RecordLoadScan\RecordLoadScan;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Application\Command\RecordProofOfDelivery\RecordProofOfDelivery;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Flujo único de punta a punta contra PostgreSQL (logistics_test): un envío DROPSHIP se despacha
 * exactamente igual que uno OWNED —preparación → carga → salida → entrega con escaneos propios—. El
 * `fulfillment_mode` del envío (leído read-only desde shipments) solo queda registrado en la entrega.
 * Requiere PostgreSQL.
 */
function dropshipFlowBus(): CommandBus
{
    return app(CommandBus::class);
}

/**
 * Inserta un envío con el fulfillment_mode dado (y dueño seller si corresponde), para que el reader lo lea.
 */
function dropshipFlowSeedShipment(string $id, string $fulfillmentMode, ?string $ownerPartyId): void
{
    DB::table('shipments')->insert([
        'id' => $id,
        'source' => 'test',
        'external_id' => 'EXT-'.$id,
        'customer_name' => 'Cliente Demo',
        'address_text' => 'Av. Siempre Viva 123',
        'address_confidence' => 80,
        'address_confidence_level' => 'high',
        'status' => 'ready',
        'fulfillment_mode' => $fulfillmentMode,
        'owner_party_id' => $ownerPartyId,
        'created_at' => '2026-10-16T00:00:00+00:00',
        'updated_at' => '2026-10-16T00:00:00+00:00',
    ]);
}

/**
 * Corre el flujo propio completo (preparación, carga, salida, POD) para el envío dado.
 */
function dropshipFlowRun(string $shipment, string $vehicle): void
{
    dropshipFlowBus()->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: $vehicle,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'prep-'.$shipment,
    ));

    dropshipFlowBus()->dispatch(new RecordLoadScan(
        shipmentId: $shipment,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T09:00:00+00:00',
        idempotencyKey: 'load-'.$shipment,
    ));

    dropshipFlowBus()->dispatch(new MarkShipmentDeparted(
        shipmentId: $shipment,
        occurredAt: '2026-10-16T10:00:00+00:00',
    ));

    dropshipFlowBus()->dispatch(new RecordProofOfDelivery(
        shipmentId: $shipment,
        unitId: 'U-1',
        confirmation: 'QR-OK-1',
        photoUrl: null,
        photoPath: null,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T12:00:00+00:00',
        idempotencyKey: 'pod-'.$shipment,
    ));
}

it('dispatches a dropship shipment with own scans exactly like an owned one', function (): void {
    $vehicle = Uuid::generate()->value;

    $owned = Uuid::generate()->value;
    dropshipFlowSeedShipment($owned, 'owned', null);
    dropshipFlowRun($owned, $vehicle);

    $dropship = Uuid::generate()->value;
    dropshipFlowSeedShipment($dropship, 'dropship', Uuid::generate()->value);
    dropshipFlowRun($dropship, $vehicle);

    $ownedRecord = DeliveryRecord::query()->where('shipment_id', $owned)->firstOrFail();
    $dropshipRecord = DeliveryRecord::query()->where('shipment_id', $dropship)->firstOrFail();

    expect($dropshipRecord->status)->toBe('DELIVERED')
        ->and($dropshipRecord->fulfillment_mode)->toBe('dropship')
        ->and($dropshipRecord->scans()->count())->toBe(3)
        ->and($dropshipRecord->dropship_handling)->toBeNull()
        ->and($ownedRecord->status)->toBe('DELIVERED')
        ->and($ownedRecord->fulfillment_mode)->toBe('owned')
        ->and($ownedRecord->scans()->count())->toBe(3);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
