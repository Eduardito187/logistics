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
use Logistics\Tracking\Infrastructure\Outbox\CancelOnOrderCancelled;

/**
 * Reactor outbox: al anularse la orden (`orders.order_cancelled`) el seguimiento pasa a «cancelada». Solo
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
function coocMessage(string $event, string $aggregateId, array $payload = []): OutboxMessage
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

it('cancela el seguimiento del pedido', function (): void {
    $order = 'order-cooc-1';
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(CancelOnOrderCancelled::class)->dispatch(coocMessage('orders.order_cancelled', $order));

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Cancelled);
});

it('ignora un evento que no es orders.order_cancelled', function (): void {
    $order = 'order-cooc-2';
    app(CommandBus::class)->dispatch(new OpenTracking(orderReference: $order));

    app(CancelOnOrderCancelled::class)->dispatch(coocMessage('orders.order_paid', $order));

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Pending);
});
