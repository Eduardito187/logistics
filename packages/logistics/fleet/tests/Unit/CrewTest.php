<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Event\CrewAssembled;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;

it('arma una tripulación con chofer, ayudantes y vehículo', function (): void {
    $driverId = DriverId::generate();
    $vehicleId = VehicleId::generate();

    $crew = Crew::assemble(
        CrewId::generate(),
        $driverId,
        ['helper-1', 'helper-2'],
        $vehicleId,
        new DateTimeImmutable('2026-10-14'),
    );

    expect($crew->driverId()->equals($driverId))->toBeTrue()
        ->and($crew->vehicleId()->equals($vehicleId))->toBeTrue()
        ->and($crew->helperIds())->toBe(['helper-1', 'helper-2'])
        ->and($crew->assembledFor()->format('Y-m-d'))->toBe('2026-10-14');

    $events = $crew->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(CrewAssembled::class)
        ->and($events[0]->assembledFor)->toBe('2026-10-14');
});

it('no duplica ayudantes repetidos', function (): void {
    $crew = Crew::assemble(
        CrewId::generate(),
        DriverId::generate(),
        ['helper-1', 'helper-1', 'helper-2'],
        VehicleId::generate(),
        new DateTimeImmutable('2026-10-14'),
    );

    expect($crew->helperIds())->toBe(['helper-1', 'helper-2']);
});

it('rechaza armar una tripulación sin chofer', function (): void {
    expect(fn () => Crew::assemble(
        CrewId::generate(),
        null,
        [],
        VehicleId::generate(),
        new DateTimeImmutable('2026-10-14'),
    ))->toThrow(FleetException::class, 'necesita un chofer');
});

it('rechaza armar una tripulación sin vehículo', function (): void {
    expect(fn () => Crew::assemble(
        CrewId::generate(),
        DriverId::generate(),
        [],
        null,
        new DateTimeImmutable('2026-10-14'),
    ))->toThrow(FleetException::class, 'necesita un vehículo');
});
