<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Event\TripCompleted;
use Logistics\Telemetry\Domain\Event\TripStarted;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Domain\ValueObject\TripStatus;

// Agregado Trip: apertura, acumulación incremental de métricas y cierre idempotente. PHP puro.

function iniciarTrip(DateTimeImmutable $at): Trip
{
    return Trip::start(
        TripId::generate(),
        'veh-1',
        null,
        'route-1',
        GeoPoint::of(0.0, 0.0),
        $at,
    );
}

it('arranca activo y graba TripStarted', function (): void {
    $trip = iniciarTrip(new DateTimeImmutable('2026-10-13T10:00:00+00:00'));

    expect($trip->status())->toBe(TripStatus::Active)
        ->and($trip->isActive())->toBeTrue()
        ->and($trip->pingCount())->toBe(0)
        ->and($trip->distanceM())->toBe(0.0);

    $events = $trip->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TripStarted::class)
        ->and($events[0]->eventName())->toBe('telemetry.trip_started');
});

it('acumula distancia, duración y tiempo en movimiento vs detenido', function (): void {
    $start = new DateTimeImmutable('2026-10-13T10:00:00+00:00');
    $trip = iniciarTrip($start);
    $trip->releaseEvents();

    // En movimiento: 1 grado de longitud (~111 km) en 100 s.
    $trip->addPoint(GeoPoint::of(0.0, 1.0), Speed::kmh(60.0), $start->modify('+100 seconds'));
    // Detenido: motor en ralentí otros 100 s sin desplazarse apenas.
    $trip->addPoint(GeoPoint::of(0.0, 1.0), Speed::kmh(0.0), $start->modify('+200 seconds'));

    expect($trip->pingCount())->toBe(2)
        ->and($trip->durationS())->toBe(200)
        ->and($trip->movingS())->toBe(100)
        ->and($trip->idleS())->toBe(100)
        ->and($trip->maxSpeedKph())->toBe(60.0)
        ->and($trip->distanceM())->toBeGreaterThan(111_000.0);

    // addPoint no emite eventos (volumen).
    expect($trip->releaseEvents())->toBe([]);
});

it('cierra el trip grabando TripCompleted y es idempotente', function (): void {
    $start = new DateTimeImmutable('2026-10-13T10:00:00+00:00');
    $trip = iniciarTrip($start);
    $trip->releaseEvents();

    $trip->complete($start->modify('+300 seconds'));

    expect($trip->status())->toBe(TripStatus::Completed)
        ->and($trip->isActive())->toBeFalse()
        ->and($trip->endedAt())->not->toBeNull();

    $events = $trip->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(TripCompleted::class)
        ->and($events[0]->eventName())->toBe('telemetry.trip_completed');

    // Segundo cierre: sin efecto ni evento.
    $trip->complete($start->modify('+600 seconds'));
    expect($trip->releaseEvents())->toBe([]);
});
