<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Event\CarrierTripCancelled;
use Logistics\Carriers\Domain\Event\CarrierTripClosed;
use Logistics\Carriers\Domain\Event\CarrierTripScheduled;
use Logistics\Carriers\Domain\Event\CarrierTripShipmentRemoved;
use Logistics\Carriers\Domain\Event\CarrierTripShipmentsAssigned;
use Logistics\Carriers\Domain\Event\CarrierTripStarted;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;

/**
 * Reglas del agregado CarrierTrip: transiciones válidas, idempotencia de iniciar/cerrar/cancelar y
 * eventos de dominio (dominio puro, sin base).
 */
function carrierTripUnitScheduled(array $shipments = ['0190a0b0-0000-7000-8000-000000000001']): CarrierTrip
{
    return CarrierTrip::schedule(
        CarrierTripId::generate(),
        '0190a0b0-0000-7000-8000-0000000000aa',
        'CD-SCZ',
        'CD-LPZ',
        new DateTimeImmutable('2026-11-13 08:00:00'),
        'MAN-001',
        $shipments,
    );
}

it('programa un viaje con su carga y graba CarrierTripScheduled', function (): void {
    $trip = carrierTripUnitScheduled(['0190A0B0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000001']);

    expect($trip->status())->toBe(CarrierTripStatus::Scheduled)
        ->and($trip->shipmentIds())->toBe(['0190a0b0-0000-7000-8000-000000000001']);

    $events = $trip->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CarrierTripScheduled::class)
        ->and($events[0]->eventName())->toBe('carriers.carrier_trip_scheduled');
});

it('rechaza un corredor con origen igual al destino', function (): void {
    CarrierTrip::schedule(
        CarrierTripId::generate(),
        'carrier',
        'CD-SCZ',
        'CD-SCZ',
        new DateTimeImmutable,
    );
})->throws(CarriersException::class);

it('inicia y cierra; repetir cada transición es idempotente (sin eventos nuevos)', function (): void {
    $trip = carrierTripUnitScheduled();
    $trip->releaseEvents();

    $startedAt = new DateTimeImmutable('2026-11-13 08:05:00');
    expect($trip->start($startedAt, '7'))->toBeTrue()
        ->and($trip->start(new DateTimeImmutable('2026-11-13 09:00:00')))->toBeFalse()
        ->and($trip->startedAt())->toEqual($startedAt)
        ->and($trip->startedBy())->toBe('7');

    $events = $trip->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CarrierTripStarted::class)
        ->and($events[0]->eventName())->toBe('carriers.carrier_trip_started');

    $closedAt = new DateTimeImmutable('2026-11-13 18:00:00');
    expect($trip->close($closedAt))->toBeTrue()
        ->and($trip->close(new DateTimeImmutable('2026-11-13 19:00:00')))->toBeFalse()
        ->and($trip->closedAt())->toEqual($closedAt)
        ->and($trip->status())->toBe(CarrierTripStatus::Closed);

    $events = $trip->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CarrierTripClosed::class)
        ->and($events[0]->eventName())->toBe('carriers.carrier_trip_closed');
});

it('no cierra un viaje programado (transición inválida)', function (): void {
    carrierTripUnitScheduled()->close(new DateTimeImmutable);
})->throws(CarriersException::class);

it('no inicia un viaje cerrado', function (): void {
    $trip = carrierTripUnitScheduled();
    $trip->start(new DateTimeImmutable);
    $trip->close(new DateTimeImmutable);

    $trip->start(new DateTimeImmutable);
})->throws(CarriersException::class);

it('no inicia un viaje sin carga', function (): void {
    carrierTripUnitScheduled([])->start(new DateTimeImmutable);
})->throws(CarriersException::class);

it('arma la carga solo mientras está programado', function (): void {
    $trip = carrierTripUnitScheduled();
    $trip->releaseEvents();

    $added = $trip->assignShipments(['0190a0b0-0000-7000-8000-000000000001', '0190a0b0-0000-7000-8000-000000000002']);
    expect($added)->toBe(['0190a0b0-0000-7000-8000-000000000002']);

    $trip->removeShipment('0190a0b0-0000-7000-8000-000000000001');
    expect($trip->shipmentIds())->toBe(['0190a0b0-0000-7000-8000-000000000002']);

    $events = $trip->releaseEvents();
    expect($events[0])->toBeInstanceOf(CarrierTripShipmentsAssigned::class)
        ->and($events[1])->toBeInstanceOf(CarrierTripShipmentRemoved::class);

    $trip->start(new DateTimeImmutable);

    expect(fn () => $trip->assignShipments(['0190a0b0-0000-7000-8000-000000000003']))
        ->toThrow(CarriersException::class);
});

it('cancela un programado (idempotente) pero no uno en ruta', function (): void {
    $trip = carrierTripUnitScheduled();
    $trip->releaseEvents();

    expect($trip->cancel(new DateTimeImmutable, 'Truck broke down'))->toBeTrue()
        ->and($trip->cancel(new DateTimeImmutable))->toBeFalse()
        ->and($trip->releaseEvents()[0])->toBeInstanceOf(CarrierTripCancelled::class);

    $running = carrierTripUnitScheduled();
    $running->start(new DateTimeImmutable);

    expect(fn () => $running->cancel(new DateTimeImmutable))->toThrow(CarriersException::class);
});
