<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Logistics\Notifications\Domain\Contract\NotificationRepository;
use Logistics\Notifications\Domain\ValueObject\MessageType;
use Logistics\Notifications\Infrastructure\Dispatch\DomainEventNotificationDispatcher;
use Logistics\Notifications\Infrastructure\Dispatch\VehicleIncidentNotificationDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;

// Un TRASPASO interno no tiene cliente: ningún hecho del envío (cargado, entregado, incidente del camión…) le
// encola un aviso. Los pedidos de cliente (domicilio y retiro en tienda) se siguen avisando igual. Se prueba
// con el cableado real (QueryBus → FindShipment → delivery_mode).
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Crea un envío de la modalidad dada (`transfer`, `store_pickup` u `home_delivery`) y devuelve su id.
 */
function tnsShipment(string $mode): string
{
    $storeId = null;
    if ($mode !== 'home_delivery') {
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
    }

    $shipment = ShipmentRecord::factory()->create([
        'external_id' => 'TNS-'.Str::random(6),
        'destination_node_id' => $storeId,
        'source' => $mode === 'transfer' ? 'TRANSFER' : 'MAGENTO',
        'status' => 'IN_EXECUTION',
    ]);

    return (string) $shipment->id;
}

/**
 * Mensaje de outbox de un hecho con el payload dado.
 *
 * @param array<string, mixed> $payload
 */
function tnsMessage(string $eventName, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'execution.delivery',
        (string) Str::uuid7(),
        $eventName,
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable('2026-11-14T15:00:00+00:00'),
        new DateTimeImmutable('2026-11-14T15:00:00+00:00'),
        null,
        null,
    );
}

it('no encola ningún aviso al cliente para un traspaso interno', function (): void {
    $shipmentId = tnsShipment('transfer');
    $dispatcher = app(DomainEventNotificationDispatcher::class);

    foreach (['execution.shipment_loaded', 'execution.shipment_delivered', 'execution.delivery_failed'] as $eventName) {
        $dispatcher->dispatch(tnsMessage($eventName, ['shipment_id' => $shipmentId, 'confirmation' => 'STORE_DROP']));
    }

    expect(DB::table('notifications')->where('shipment_id', $shipmentId)->count())->toBe(0);
});

it('sigue avisando la entrega de los pedidos de cliente', function (string $mode): void {
    $shipmentId = tnsShipment($mode);
    $dispatcher = app(DomainEventNotificationDispatcher::class);

    $message = tnsMessage('execution.shipment_delivered', ['shipment_id' => $shipmentId, 'confirmation' => 'QR']);
    $dispatcher->dispatch($message);
    // Reentrega del outbox: un solo aviso.
    $dispatcher->dispatch($message);

    $rows = DB::table('notifications')->where('shipment_id', $shipmentId)->get();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->message_type)->toBe(MessageType::Delivered->value);
})->with(['home_delivery', 'store_pickup']);

it('ante un incidente del camión no le avisa a un traspaso, pero sí a los pedidos de cliente', function (): void {
    $transferId = tnsShipment('transfer');
    $customerId = tnsShipment('home_delivery');
    $repo = app(NotificationRepository::class);

    app(VehicleIncidentNotificationDispatcher::class)->dispatch(tnsMessage('execution.vehicle_incident_reported', [
        'severity' => 'MAJOR',
        'affected_shipment_ids' => [$customerId, $transferId],
    ]));

    expect($repo->existsForShipmentAndType($customerId, MessageType::Rescheduled))->toBeTrue()
        ->and($repo->existsForShipmentAndType($transferId, MessageType::Rescheduled))->toBeFalse()
        // El aviso interno al supervisor sale igual.
        ->and($repo->existsForShipmentAndType($customerId, MessageType::SupervisorIncident))->toBeTrue();
});
