<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\OpenNodeReception\OpenNodeReception;
use Logistics\Execution\Application\Command\ReceiveUnitAtNode\ReceiveUnitAtNode;
use Logistics\Execution\Application\Command\ReconcileNodeReception\ReconcileNodeReception;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionRecord;
use Logistics\Execution\Infrastructure\Persistence\Eloquent\NodeReceptionUnitRecord;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Flujo completo de recepción en nodo contra PostgreSQL (logistics_test): abrir la recepción de un envío
 * en un nodo destino por un corredor interno, escanear los bultos recibidos y conciliar lo recibido
 * contra lo esperado (faltantes/sobrantes). Cada paso deja su hecho inmutable en el outbox.
 */
function bus(): CommandBus
{
    return app(CommandBus::class);
}

$shipment = 'd1111111-1111-7111-8111-111111111111';
$origin = 'a1111111-1111-7111-8111-111111111111';
$destination = 'b2222222-2222-7222-8222-222222222222';

function openNodeReception(string $shipment, string $origin, string $destination): void
{
    bus()->dispatch(new OpenNodeReception(
        shipmentId: $shipment,
        originNodeId: $origin,
        destinationNodeId: $destination,
        originNodeKind: 'CD',
        destinationNodeKind: 'STORE',
        expectedUnitIds: ['U-1', 'U-2', 'U-3'],
        occurredAt: '2026-10-13T08:00:00+00:00',
    ));
}

function receiveAtNode(string $shipment, string $destination, string $unitId, string $key): void
{
    bus()->dispatch(new ReceiveUnitAtNode(
        shipmentId: $shipment,
        destinationNodeId: $destination,
        unitId: $unitId,
        lat: -16.5,
        lng: -68.15,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T09:00:00+00:00',
        idempotencyKey: $key,
    ));
}

it('opens a node reception and records it with its expected units and corridor', function () use ($shipment, $origin, $destination): void {
    openNodeReception($shipment, $origin, $destination);

    $record = NodeReceptionRecord::query()->where('shipment_id', $shipment)->firstOrFail();

    expect($record->corridor)->toBe('CD_TO_STORE')
        ->and($record->status)->toBe('OPEN')
        ->and($record->expected_unit_ids)->toBe(['U-1', 'U-2', 'U-3']);

    $message = OutboxMessageRecord::query()
        ->where('aggregate_type', 'execution.node_reception')
        ->where('event_name', 'execution.node_reception_opened')
        ->firstOrFail();

    expect($message->payload['expected_count'])->toBe(3);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('is idempotent on open: a second open does not duplicate the reception', function () use ($shipment, $origin, $destination): void {
    openNodeReception($shipment, $origin, $destination);
    openNodeReception($shipment, $origin, $destination);

    expect(NodeReceptionRecord::query()->where('shipment_id', $shipment)->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('records each received unit as an append-only row, idempotent per key', function () use ($shipment, $origin, $destination): void {
    openNodeReception($shipment, $origin, $destination);
    receiveAtNode($shipment, $destination, 'U-1', 'k-1');
    receiveAtNode($shipment, $destination, 'U-1', 'k-1'); // reenvío offline: no duplica

    expect(NodeReceptionUnitRecord::query()->where('unit_id', 'U-1')->count())->toBe(1);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('reconciles received against expected and records missing and surplus in the outbox', function () use ($shipment, $origin, $destination): void {
    openNodeReception($shipment, $origin, $destination);
    receiveAtNode($shipment, $destination, 'U-1', 'k-1');
    receiveAtNode($shipment, $destination, 'U-2', 'k-2');
    receiveAtNode($shipment, $destination, 'U-9', 'k-9'); // sobrante (no esperado)

    bus()->dispatch(new ReconcileNodeReception(
        shipmentId: $shipment,
        destinationNodeId: $destination,
        deviceId: 'device-01',
        userId: 'user-07',
        occurredAt: '2026-10-13T10:00:00+00:00',
    ));

    $record = NodeReceptionRecord::query()->where('shipment_id', $shipment)->firstOrFail();
    expect($record->status)->toBe('RECONCILED')
        ->and($record->reconciled_at)->not->toBeNull();

    $message = OutboxMessageRecord::query()
        ->where('aggregate_type', 'execution.node_reception')
        ->where('event_name', 'execution.node_reception_reconciled')
        ->firstOrFail();

    expect($message->payload['missing_unit_ids'])->toBe(['U-3'])
        ->and($message->payload['surplus_unit_ids'])->toBe(['U-9'])
        ->and($message->payload['expected_count'])->toBe(3)
        ->and($message->payload['received_count'])->toBe(3)
        ->and($message->payload['complete'])->toBeFalse();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
