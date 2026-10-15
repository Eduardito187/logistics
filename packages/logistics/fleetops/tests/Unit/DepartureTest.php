<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\DepartureClosed;
use Logistics\FleetOps\Domain\Event\DepartureRegistered;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Domain\ValueObject\Load;
use Logistics\FleetOps\Domain\ValueObject\Odometer;

function registrarSalida(?int $odometerOut = 100_000): Departure
{
    return Departure::register(
        DepartureId::generate(),
        'vehicle-1',
        'driver-1',
        ['helper-1'],
        'Cochabamba',
        'RT-01',
        Load::of('Línea blanca', 1_200.0),
        $odometerOut !== null ? Odometer::km($odometerOut) : null,
        new DateTimeImmutable('2026-10-10T06:00:00+00:00'),
        null,
    );
}

it('registra una salida en ruta (Out) y graba el evento', function (): void {
    $departure = registrarSalida();

    expect($departure->status())->toBe(DepartureStatus::Out)
        ->and($departure->odometerIn())->toBeNull()
        ->and($departure->kmTraveled())->toBeNull()
        ->and($departure->helperIds())->toBe(['helper-1']);

    $events = $departure->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DepartureRegistered::class);
});

it('al cerrar calcula los km recorridos y graba el evento', function (): void {
    $departure = registrarSalida(100_000);
    $departure->releaseEvents();

    $departure->close(Odometer::km(100_450), new DateTimeImmutable('2026-10-11T18:00:00+00:00'));

    expect($departure->status())->toBe(DepartureStatus::Returned)
        ->and($departure->odometerIn()?->km)->toBe(100_450)
        ->and($departure->kmTraveled())->toBe(450);

    $events = $departure->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(DepartureClosed::class);
});

it('rechaza un odómetro de regreso menor al de salida', function (): void {
    $departure = registrarSalida(100_000);

    expect(fn () => $departure->close(Odometer::km(99_000), new DateTimeImmutable('2026-10-11T18:00:00+00:00')))
        ->toThrow(FleetOpsException::class, 'no puede ser menor al de salida');
});

it('no se puede cerrar dos veces', function (): void {
    $departure = registrarSalida(100_000);
    $departure->close(Odometer::km(100_450), new DateTimeImmutable('2026-10-11T18:00:00+00:00'));

    expect(fn () => $departure->close(Odometer::km(100_900), new DateTimeImmutable('2026-10-12T18:00:00+00:00')))
        ->toThrow(FleetOpsException::class, 'ya fue cerrada');
});

it('rechaza registrar sin vehículo', function (): void {
    expect(fn () => Departure::register(
        DepartureId::generate(),
        '  ',
        null,
        [],
        null,
        null,
        Load::empty(),
        null,
        new DateTimeImmutable('2026-10-10T06:00:00+00:00'),
        null,
    ))->toThrow(FleetOpsException::class, 'debe referenciar un vehículo');
});
