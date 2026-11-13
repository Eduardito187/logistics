<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\BindShipmentToTracking\BindShipmentToTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\ReturnedOnReturnInitiated;

/**
 * Reactor outbox: al iniciarse una devolución (`returns.return_initiated`) el seguimiento entregado pasa a
 * «devuelta»; el `original_reference` lleva el orderId (o orderId#n:nodeId). Contra logistics_test.
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
function rrriMessage(string $event, string $aggregateId, array $payload): OutboxMessage
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

function rrriDelivered(string $order): void
{
    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: Uuid::generate()->value));
    foreach ([
        VisibleStatus::Processing,
        VisibleStatus::InProgress,
        VisibleStatus::Loaded,
        VisibleStatus::OnTheWay,
        VisibleStatus::Delivered,
    ] as $status) {
        $bus->dispatch(new AdvanceTracking(status: $status, orderReference: $order));
    }
}

it('marca devuelto el seguimiento entregado derivando el orderReference', function (): void {
    $order = 'order-rrri-1';
    rrriDelivered($order);

    app(ReturnedOnReturnInitiated::class)->dispatch(
        rrriMessage('returns.return_initiated', Uuid::generate()->value, ['original_reference' => $order.'#n:1:node-a']),
    );

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Returned);
});

it('no hace nada si falta el original_reference', function (): void {
    $order = 'order-rrri-2';
    rrriDelivered($order);

    app(ReturnedOnReturnInitiated::class)->dispatch(
        rrriMessage('returns.return_initiated', Uuid::generate()->value, ['original_reference' => '']),
    );

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Delivered);
});

it('ignora un evento que no es returns.return_initiated', function (): void {
    $order = 'order-rrri-3';
    rrriDelivered($order);

    app(ReturnedOnReturnInitiated::class)->dispatch(
        rrriMessage('returns.other', Uuid::generate()->value, ['original_reference' => $order]),
    );

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Delivered);
});
