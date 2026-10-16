<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Execution\Application\Command\ConfirmDropshipDelivery\ConfirmDropshipDelivery;
use Logistics\Execution\Application\Command\ConfirmDropshipDispatch\ConfirmDropshipDispatch;
use Logistics\Execution\Application\Command\RecordPreparationScan\RecordPreparationScan;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\DeliveryRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Flujo dropship de punta a punta contra PostgreSQL (logistics_test): el envío es DROPSHIP (seller
 * despacha), la ejecución se cierra por confirmación del seller SIN escaneos propios, y el agregado
 * rechaza un escaneo propio sobre una entrega dropship de solo-tracking. La bifurcación la decide el
 * fulfillment_mode persistido del envío (leído read-only desde shipments). Requiere PostgreSQL.
 */
function dropshipBus(): CommandBus
{
    return app(CommandBus::class);
}

/**
 * Inserta un envío con el fulfillment_mode dado (y dueño seller si corresponde), para que el reader lo lea.
 */
function seedShipment(string $id, string $fulfillmentMode, ?string $ownerPartyId): void
{
    DB::table('shipments')->insert([
        'id' => $id,
        'source' => 'test',
        'external_id' => 'EXT-'.substr($id, 0, 8),
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

it('closes a dropship shipment by seller confirmation without any own scan', function (): void {
    $shipment = Uuid::generate()->value;
    $seller = Uuid::generate()->value;
    $carrier = Uuid::generate()->value;
    seedShipment($shipment, 'dropship', $seller);

    dropshipBus()->dispatch(new ConfirmDropshipDispatch(
        shipmentId: $shipment,
        carrierTransportId: $carrier,
        handling: 'track_only',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'disp-1',
        reference: 'SELLER-GUIDE-1',
    ));

    $afterDispatch = DeliveryRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($afterDispatch->status)->toBe('IN_TRANSIT')
        ->and($afterDispatch->fulfillment_mode)->toBe('dropship')
        ->and($afterDispatch->dropship_handling)->toBe('track_only')
        ->and($afterDispatch->scans()->count())->toBe(0);

    dropshipBus()->dispatch(new ConfirmDropshipDelivery(
        shipmentId: $shipment,
        occurredAt: '2026-10-16T18:00:00+00:00',
        idempotencyKey: 'deliv-1',
        reference: 'POD-SELLER-1',
    ));

    $afterDelivery = DeliveryRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($afterDelivery->status)->toBe('DELIVERED')
        ->and($afterDelivery->scans()->count())->toBe(0);

    expect(OutboxMessageRecord::query()->where('event_name', 'execution.dropship_dispatch_confirmed')->exists())->toBeTrue()
        ->and(OutboxMessageRecord::query()->where('event_name', 'execution.dropship_delivered')->exists())->toBeTrue();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('rejects an own preparation scan on a track-only dropship delivery', function (): void {
    $shipment = Uuid::generate()->value;
    $seller = Uuid::generate()->value;
    $carrier = Uuid::generate()->value;
    seedShipment($shipment, 'dropship', $seller);

    dropshipBus()->dispatch(new ConfirmDropshipDispatch(
        shipmentId: $shipment,
        carrierTransportId: $carrier,
        handling: 'track_only',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'disp-2',
    ));

    expect(fn () => dropshipBus()->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: $carrier,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T09:00:00+00:00',
        idempotencyKey: 'prep-x',
    )))->toThrow(ExecutionException::class, 'no aplica en una entrega dropship');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('refuses a dropship dispatch for a non-dropship (owned) shipment', function (): void {
    $shipment = Uuid::generate()->value;
    $carrier = Uuid::generate()->value;
    seedShipment($shipment, 'owned', null);

    expect(fn () => dropshipBus()->dispatch(new ConfirmDropshipDispatch(
        shipmentId: $shipment,
        carrierTransportId: $carrier,
        handling: 'track_only',
        occurredAt: '2026-10-16T08:00:00+00:00',
        idempotencyKey: 'disp-3',
    )))->toThrow(ExecutionException::class, 'no es dropship');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('keeps an owned shipment on the own-scan flow when a preparation scan opens the delivery', function (): void {
    $shipment = Uuid::generate()->value;
    $vehicle = Uuid::generate()->value;
    seedShipment($shipment, 'owned', null);

    dropshipBus()->dispatch(new RecordPreparationScan(
        shipmentId: $shipment,
        vehicleId: $vehicle,
        unitId: 'U-1',
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-16T09:00:00+00:00',
        idempotencyKey: 'prep-owned',
    ));

    $record = DeliveryRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($record->status)->toBe('PREPARED')
        ->and($record->fulfillment_mode)->toBe('owned')
        ->and($record->dropship_handling)->toBeNull();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
