<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Tracking\Application\Command\AdvanceTracking\AdvanceTracking;
use Logistics\Tracking\Application\Command\OpenTracking\OpenTracking;
use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\Outbox\InProgressOnLabelsEmitted;

/**
 * Reactor outbox: al emitir las etiquetas (`labels.labels_emitted`) el seguimiento pasa a «en curso»; el
 * `order_number` es el external_id del envío (orderId o orderId#n:nodeId). Contra logistics_test.
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
function ipleMessage(string $event, string $aggregateId, array $payload): OutboxMessage
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

function ipleProcessing(string $order): void
{
    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));
}

it('avanza a en curso derivando el orderReference del order_number con sufijo de nodo', function (): void {
    $order = 'order-iple-1';
    ipleProcessing($order);

    app(InProgressOnLabelsEmitted::class)->dispatch(
        ipleMessage('labels.labels_emitted', Uuid::generate()->value, ['order_number' => $order.'#n:1:node-a']),
    );

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::InProgress);
});

it('ignora un evento que no es labels.labels_emitted', function (): void {
    $order = 'order-iple-2';
    ipleProcessing($order);

    app(InProgressOnLabelsEmitted::class)->dispatch(
        ipleMessage('labels.other', Uuid::generate()->value, ['order_number' => $order]),
    );

    expect(app(TrackingRepository::class)->findByOrderReference($order)?->status())
        ->toBe(VisibleStatus::Processing);
});
