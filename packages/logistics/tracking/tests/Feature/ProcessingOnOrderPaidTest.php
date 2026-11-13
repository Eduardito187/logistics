<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\ProcessingOnOrderPaid;

/**
 * Reactor outbox: al pagarse la orden (`orders.order_paid`) el seguimiento pasa a «procesando». Solo
 * reacciona a su evento. Contra logistics_test.
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
function popMessage(string $event, string $aggregateId, array $payload = []): OutboxMessage
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

it('avanza el seguimiento del pedido a procesando', function (): void {
    $order = 'order-pop-1';
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(ProcessingOnOrderPaid::class)->dispatch(popMessage('orders.order_paid', $order));

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Processing);
});

it('ignora un evento que no es orders.order_paid', function (): void {
    $order = 'order-pop-2';
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(ProcessingOnOrderPaid::class)->dispatch(popMessage('orders.order_cancelled', $order));

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Pending);
});
