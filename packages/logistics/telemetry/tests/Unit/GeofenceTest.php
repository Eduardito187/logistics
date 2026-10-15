<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Event\GeofenceActivated;
use Logistics\Telemetry\Domain\Event\GeofenceDeactivated;
use Logistics\Telemetry\Domain\Event\GeofenceDefined;
use Logistics\Telemetry\Domain\Event\GeofenceRedrawn;
use Logistics\Telemetry\Domain\Event\GeofenceRenamed;
use Logistics\Telemetry\Domain\Exception\TelemetryException;
use Logistics\Telemetry\Domain\Model\Geofence;
use Logistics\Telemetry\Domain\ValueObject\GeofenceId;
use Logistics\Telemetry\Domain\ValueObject\GeofenceType;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Polygon;

// Agregado Geofence: ciclo de vida y pertenencia solo si está activa. PHP puro.

function poligonoCuadrado(): Polygon
{
    return Polygon::fromArray([
        ['lat' => -1.0, 'lng' => -1.0],
        ['lat' => -1.0, 'lng' => 1.0],
        ['lat' => 1.0, 'lng' => 1.0],
        ['lat' => 1.0, 'lng' => -1.0],
    ]);
}

function definirGeocerca(GeofenceType $type = GeofenceType::ForbiddenZone): Geofence
{
    return Geofence::define(
        GeofenceId::generate(),
        'Zona Centro',
        $type,
        poligonoCuadrado(),
    );
}

it('define una geocerca activa y graba GeofenceDefined', function (): void {
    $geofence = definirGeocerca();

    expect($geofence->isActive())->toBeTrue()
        ->and($geofence->name())->toBe('Zona Centro')
        ->and($geofence->type())->toBe(GeofenceType::ForbiddenZone);

    $events = $geofence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GeofenceDefined::class);
});

it('rechaza definir con nombre vacío', function (): void {
    expect(fn () => Geofence::define(
        GeofenceId::generate(),
        '   ',
        GeofenceType::RestArea,
        poligonoCuadrado(),
    ))->toThrow(TelemetryException::class);
});

it('renombra grabando el cambio y es idempotente con el mismo nombre', function (): void {
    $geofence = definirGeocerca();
    $geofence->releaseEvents();

    $geofence->rename('Zona Norte');
    expect($geofence->name())->toBe('Zona Norte');

    $events = $geofence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GeofenceRenamed::class);

    $geofence->rename('Zona Norte');
    expect($geofence->releaseEvents())->toBe([]);
});

it('redibuja el polígono grabando GeofenceRedrawn', function (): void {
    $geofence = definirGeocerca();
    $geofence->releaseEvents();

    $geofence->redraw(Polygon::fromArray([
        ['lat' => -2.0, 'lng' => -2.0],
        ['lat' => -2.0, 'lng' => 2.0],
        ['lat' => 2.0, 'lng' => 2.0],
        ['lat' => 2.0, 'lng' => -2.0],
    ]));

    $events = $geofence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GeofenceRedrawn::class);
});

it('activa y desactiva de forma idempotente', function (): void {
    $geofence = definirGeocerca();
    $geofence->releaseEvents();

    // Ya está activa: activar de nuevo no hace nada.
    $geofence->activate();
    expect($geofence->releaseEvents())->toBe([]);

    $geofence->deactivate();
    expect($geofence->isActive())->toBeFalse();
    $events = $geofence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GeofenceDeactivated::class);

    // Ya está inactiva: desactivar de nuevo no hace nada.
    $geofence->deactivate();
    expect($geofence->releaseEvents())->toBe([]);

    $geofence->activate();
    $events = $geofence->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(GeofenceActivated::class);
});

it('contiene un punto solo si está activa', function (): void {
    $geofence = definirGeocerca();
    $inside = GeoPoint::of(0.0, 0.0);

    expect($geofence->contains($inside))->toBeTrue()
        ->and($geofence->contains(GeoPoint::of(5.0, 5.0)))->toBeFalse();

    $geofence->deactivate();
    expect($geofence->contains($inside))->toBeFalse();
});
