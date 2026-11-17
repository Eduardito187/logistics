<?php

declare(strict_types=1);

use Illuminate\Support\Str;
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
use Logistics\Tracking\Infrastructure\Outbox\HandToCarrierOnCarrierTripStarted;

// Reactor outbox: al iniciar un viaje de transportadora (`carriers.carrier_trip_started`) cada envío de la
// carga pasa a «entregado a la transportadora», por `shipmentId`. Tolerante. Contra logistics_test.
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * @param array<string, mixed> $payload
 */
function htcMessage(string $event, array $payload): OutboxMessage
{
    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'test.aggregate',
        Uuid::generate()->value,
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

/** Abre un seguimiento, le liga un envío y lo lleva a IN_PROGRESS (estado operativo). Devuelve el shipmentId. */
function htcInProgressShipment(): string
{
    $order = 'ORD-HTC-'.Str::random(8);
    $shipment = (string) Str::uuid7();
    $bus = app(CommandBus::class);
    $bus->dispatch(new OpenTracking(orderReference: $order));
    $bus->dispatch(new BindShipmentToTracking(orderReference: $order, shipmentId: $shipment));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::Processing, orderReference: $order));
    $bus->dispatch(new AdvanceTracking(status: VisibleStatus::InProgress, orderReference: $order));

    return $shipment;
}

it('marca HANDED_TO_CARRIER cada envío del viaje y tolera uno desconocido', function (): void {
    $shipmentA = htcInProgressShipment();
    $shipmentB = htcInProgressShipment();
    $unknown = (string) Str::uuid7();

    app(HandToCarrierOnCarrierTripStarted::class)->dispatch(htcMessage(
        'carriers.carrier_trip_started',
        ['shipment_ids' => [$shipmentA, $shipmentB, $unknown]],
    ));

    $trackings = app(TrackingRepository::class);
    expect($trackings->findByShipmentId($shipmentA)?->status())->toBe(VisibleStatus::HandedToCarrier)
        ->and($trackings->findByShipmentId($shipmentB)?->status())->toBe(VisibleStatus::HandedToCarrier)
        ->and($trackings->findByShipmentId($unknown))->toBeNull();
});

it('ignora un evento que no es carriers.carrier_trip_started', function (): void {
    $shipment = htcInProgressShipment();

    app(HandToCarrierOnCarrierTripStarted::class)->dispatch(htcMessage(
        'carriers.carrier_trip_finished',
        ['shipment_ids' => [$shipment]],
    ));

    expect(app(TrackingRepository::class)->findByShipmentId($shipment)?->status())
        ->toBe(VisibleStatus::InProgress);
});
