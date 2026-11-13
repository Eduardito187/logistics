<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\OpenTrackingOnOrderPlaced;

/**
 * Reactor outbox: al crear la orden (`orders.order_placed`) abre el seguimiento en «pendiente». Solo
 * reacciona a su evento; cualquier otro lo ignora. Contra logistics_test.
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
function otopMessage(string $event, string $aggregateId, array $payload = []): OutboxMessage
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

it('abre el seguimiento del pedido en pendiente', function (): void {
    $order = 'order-otop-1';

    app(OpenTrackingOnOrderPlaced::class)->dispatch(otopMessage('orders.order_placed', $order));

    $tracking = app(TrackingRepository::class)->findByOrderReference($order);
    expect($tracking)->not->toBeNull()
        ->and($tracking->status())->toBe(VisibleStatus::Pending);
});

it('ignora un evento que no es orders.order_placed', function (): void {
    $order = 'order-otop-2';

    app(OpenTrackingOnOrderPlaced::class)->dispatch(otopMessage('orders.order_paid', $order));

    expect(app(TrackingRepository::class)->findByOrderReference($order))->toBeNull();
});
