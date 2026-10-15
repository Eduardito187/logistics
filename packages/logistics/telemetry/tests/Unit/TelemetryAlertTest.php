<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Event\AlertAcknowledged;
use Logistics\Telemetry\Domain\Event\AlertRaised;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertId;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

// Agregado TelemetryAlert: levantar y reconocer (idempotente). PHP puro.

function levantarAlerta(): TelemetryAlert
{
    return TelemetryAlert::raise(
        AlertId::generate(),
        'veh-1',
        null,
        AlertType::Speeding,
        AlertSeverity::Warning,
        GeoPoint::of(-16.5, -68.15),
        ['speed_kph' => 100.0, 'limit_kph' => 90.0],
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );
}

it('levanta una alerta sin reconocer y graba AlertRaised', function (): void {
    $alert = levantarAlerta();

    expect($alert->isAcknowledged())->toBeFalse()
        ->and($alert->type())->toBe(AlertType::Speeding)
        ->and($alert->severity())->toBe(AlertSeverity::Warning);

    $events = $alert->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AlertRaised::class)
        ->and($events[0]->eventName())->toBe('telemetry.alert_raised');
});

it('reconoce la alerta grabando AlertAcknowledged y es idempotente', function (): void {
    $alert = levantarAlerta();
    $alert->releaseEvents();

    $alert->acknowledge('supervisor@dismac.bo', new DateTimeImmutable('2026-10-13T10:05:00+00:00'));

    expect($alert->isAcknowledged())->toBeTrue()
        ->and($alert->acknowledgedBy())->toBe('supervisor@dismac.bo');

    $events = $alert->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(AlertAcknowledged::class);

    // Segundo acknowledge: sin efecto ni evento.
    $alert->acknowledge('otro@dismac.bo', new DateTimeImmutable('2026-10-13T11:00:00+00:00'));
    expect($alert->releaseEvents())->toBe([])
        ->and($alert->acknowledgedBy())->toBe('supervisor@dismac.bo');
});
