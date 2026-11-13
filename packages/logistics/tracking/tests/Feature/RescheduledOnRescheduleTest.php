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
use Logistics\Tracking\Infrastructure\Outbox\RescheduledOnReschedule;

/**
 * Reactor outbox: al reprogramarse la entrega (`shipments.shipment_rescheduled`) el seguimiento del envío
 * pasa a «reprogramada» (por shipment_id del payload). Contra logistics_test.
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
function rorMessage(string $event, string $aggregateId, array $payload): OutboxMessage
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

function rorProcessingShipment(string $order): string
{
    $bus = app(CommandBus::class);
    $shipment = Uuid::generate()->value;
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));

    return $shipment;
}

it('reprograma el seguimiento del envío por shipment_id', function (): void {
    $shipment = rorProcessingShipment('order-ror-1');

    app(RescheduledOnReschedule::class)->dispatch(
        rorMessage('shipments.shipment_rescheduled', Uuid::generate()->value, ['shipment_id' => $shipment]),
    );

    expect(app(TrackingRepository::class)->findByShipmentId($shipment)?->status())
        ->toBe(VisibleStatus::Rescheduled);
});

it('ignora un evento que no es shipments.shipment_rescheduled', function (): void {
    $shipment = rorProcessingShipment('order-ror-2');

    app(RescheduledOnReschedule::class)->dispatch(
        rorMessage('shipments.other', Uuid::generate()->value, ['shipment_id' => $shipment]),
    );

    expect(app(TrackingRepository::class)->findByShipmentId($shipment)?->status())
        ->toBe(VisibleStatus::Processing);
});
