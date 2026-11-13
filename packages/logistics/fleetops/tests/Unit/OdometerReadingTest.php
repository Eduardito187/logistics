<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\OdometerRecorded;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;

/**
 * Cubre el agregado OdometerReading (histórico append-only): registro con su evento, rechazo de vehículo
 * vacío y reconstitución desde persistencia sin eventos.
 */
it('registra una lectura de odómetro y graba el evento', function (): void {
    $readAt = new DateTimeImmutable('2026-10-10T08:00:00+00:00');
    $reading = OdometerReading::record(
        OdometerReadingId::generate(),
        'vehicle-1',
        Odometer::km(120_000),
        OdometerSource::Manual,
        $readAt,
        'ingreso a taller',
    );

    expect($reading->vehicleId())->toBe('vehicle-1')
        ->and($reading->odometer()->km)->toBe(120_000)
        ->and($reading->source())->toBe(OdometerSource::Manual)
        ->and($reading->readAt()->format('Y-m-d'))->toBe('2026-10-10')
        ->and($reading->note())->toBe('ingreso a taller');

    $events = $reading->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(OdometerRecorded::class);
});

it('rechaza registrar una lectura sin vehículo', function (): void {
    expect(fn () => OdometerReading::record(
        OdometerReadingId::generate(),
        '   ',
        Odometer::km(1_000),
        OdometerSource::Telemetry,
        new DateTimeImmutable('2026-10-10T08:00:00+00:00'),
        null,
    ))->toThrow(FleetOpsException::class, 'debe referenciar un vehículo');
});

it('reconstituye una lectura sin grabar eventos', function (): void {
    $reading = OdometerReading::reconstitute(
        OdometerReadingId::generate(),
        'vehicle-2',
        Odometer::km(99_000),
        OdometerSource::Telemetry,
        new DateTimeImmutable('2026-10-09T07:00:00+00:00'),
        null,
    );

    expect($reading->vehicleId())->toBe('vehicle-2')
        ->and($reading->note())->toBeNull()
        ->and($reading->releaseEvents())->toBe([]);
});
