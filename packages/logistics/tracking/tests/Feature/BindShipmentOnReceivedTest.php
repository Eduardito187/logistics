<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Infrastructure\Outbox\BindShipmentOnReceived;

/**
 * Reactor outbox: al crear el envío (`shipments.shipment_received`) lo liga al seguimiento de su pedido; el
 * `external_id` lleva el orderId (o orderId#n:nodeId). Tolerante si falta el external_id. Contra logistics_test.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $payload
 */
function borMessage(string $event, string $aggregateId, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'test.aggregate',
        $aggregateId,
        $event,
        $payload,
        OutboxStatus::Pending,
        0,
        new DateTimeImmutable,
        new DateTimeImmutable,
        null,
        null,
    );
}

it('liga el envío al seguimiento del pedido derivando el orderReference', function (): void {
    $order = 'order-bor-1';
    $shipment = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(BindShipmentOnReceived::class)->dispatch(borMessage(
        'shipments.shipment_received',
        $shipment,
        ['shipment_id' => $shipment, 'external_id' => $order.'#n:1:node-a'],
    ));

    expect(app(TrackingRepository::class)->findByShipmentId($shipment))->not->toBeNull();
});

it('no liga nada si falta el external_id', function (): void {
    $order = 'order-bor-2';
    $shipment = Uuid::generate()->value;
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(BindShipmentOnReceived::class)->dispatch(borMessage(
        'shipments.shipment_received',
        $shipment,
        ['shipment_id' => $shipment, 'external_id' => ''],
    ));

    expect(app(TrackingRepository::class)->findByShipmentId($shipment))->toBeNull();
});

it('ignora un evento que no es shipments.shipment_received', function (): void {
    $shipment = Uuid::generate()->value;

    app(BindShipmentOnReceived::class)->dispatch(borMessage(
        'shipments.other',
        $shipment,
        ['shipment_id' => $shipment, 'external_id' => 'order-bor-3'],
    ));

    expect(app(TrackingRepository::class)->findByShipmentId($shipment))->toBeNull();
});
