<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\Service\TripAction;
use Logistics\Telemetry\Domain\Service\TripTracker;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Domain\ValueObject\TripId;

// Servicio de dominio TripTracker: decide abrir/continuar/cerrar un trip según el hueco temporal. PHP puro.

function pingEn(DateTimeImmutable $recordedAt): VehiclePing
{
    return VehiclePing::record(
        PingId::generate(),
        'veh-1',
        null,
        null,
        null,
        GeoPoint::of(0.0, 0.0),
        Speed::kmh(40.0),
        null,
        null,
        true,
        null,
        $recordedAt,
        $recordedAt,
        PingSource::App,
    );
}

function tripActivoEn(DateTimeImmutable $startedAt): Trip
{
    return Trip::start(TripId::generate(), 'veh-1', null, null, GeoPoint::of(0.0, 0.0), $startedAt);
}

it('abre un trip nuevo cuando no hay trip activo', function (): void {
    $tracker = new TripTracker;
    $ping = pingEn(new DateTimeImmutable('2026-10-13T10:00:00+00:00'));

    expect($tracker->decide(null, $ping, 10, 3.0))->toBe(TripAction::StartNew);
});

it('abre un trip nuevo cuando el trip existente ya está cerrado', function (): void {
    $tracker = new TripTracker;
    $start = new DateTimeImmutable('2026-10-13T10:00:00+00:00');
    $trip = tripActivoEn($start);
    $trip->complete($start->modify('+60 seconds'));

    $ping = pingEn($start->modify('+120 seconds'));
    expect($tracker->decide($trip, $ping, 10, 3.0))->toBe(TripAction::StartNew);
});

it('continúa el trip cuando el hueco temporal es menor al límite', function (): void {
    $tracker = new TripTracker;
    $start = new DateTimeImmutable('2026-10-13T10:00:00+00:00');
    $trip = tripActivoEn($start);

    // Último punto del trip = startedAt (duración 0). Hueco de 5 min < 10 min.
    $ping = pingEn($start->modify('+5 minutes'));
    expect($tracker->decide($trip, $ping, 10, 3.0))->toBe(TripAction::Continue);
});

it('cierra y abre uno nuevo cuando el hueco temporal supera el límite', function (): void {
    $tracker = new TripTracker;
    $start = new DateTimeImmutable('2026-10-13T10:00:00+00:00');
    $trip = tripActivoEn($start);

    // Hueco de 15 min > 10 min.
    $ping = pingEn($start->modify('+15 minutes'));
    expect($tracker->decide($trip, $ping, 10, 3.0))->toBe(TripAction::CloseAndStartNew);
});
