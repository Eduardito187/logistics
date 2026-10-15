<?php

declare(strict_types=1);

use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Infrastructure\LiveState\BroadcastingLivePositionBroadcaster;
use Logistics\Telemetry\Infrastructure\LiveState\FleetPlateResolver;
use Logistics\Telemetry\Infrastructure\LiveState\LiveVehicleState;

/**
 * Cobertura del fan-out en vivo (ADR 016): el hot-state en cache y el adaptador broadcaster. Se usa el
 * store de cache `array` (sin Valkey ni servidor WebSocket real) fijándolo por config, para ejercitar el
 * camino feliz de remember/get/shouldEmit y publish() sin infraestructura externa. El broadcast real se
 * difiere a afterCommit y bajo RefreshDatabase no llega a emitirse, de modo que nada sale del proceso.
 */
beforeEach(function (): void {
    // Store en memoria: el adaptador degrada con gracia si no está, pero así cubrimos el camino feliz.
    config(['logistics-telemetry.live.cache_store' => 'array']);
});

/**
 * Construye un ping de dominio mínimo para alimentar el broadcaster.
 */
function coverageLivePing(string $vehicleId, bool $moving): VehiclePing
{
    return VehiclePing::record(
        PingId::generate(),
        $vehicleId,
        null,
        null,
        null,
        GeoPoint::of(-16.5, -68.15),
        $moving ? Speed::kmh(40.0) : Speed::zero(),
        Heading::degrees(90),
        null,
        true,
        null,
        new DateTimeImmutable('2026-10-13T10:00:00-04:00'),
        new DateTimeImmutable('2026-10-13T10:00:01-04:00'),
        PingSource::App,
        null,
    );
}

it('recuerda la última posición y la devuelve desde el hot-state', function (): void {
    $state = new LiveVehicleState;
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000000aa';

    $payload = [
        'vehicle_id' => $vehicleId,
        'plate' => 'ABC-123',
        'lat' => -16.5,
        'lng' => -68.15,
        'status' => 'moving',
        'occurred_at' => '2026-10-13T10:00:00-04:00',
    ];

    $state->remember($vehicleId, $payload);

    expect($state->get($vehicleId))->toBe($payload)
        ->and($state->get('0192aaaa-bbbb-7ccc-8ddd-0000000000bb'))->toBeNull();
});

it('aplica el throttle de difusión: deja pasar la primera y corta la siguiente', function (): void {
    $state = new LiveVehicleState;
    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000000cc';

    expect($state->shouldEmit($vehicleId))->toBeTrue()
        ->and($state->shouldEmit($vehicleId))->toBeFalse();
});

it('publica una posición en movimiento refrescando el hot-state', function (): void {
    $state = new LiveVehicleState;
    $broadcaster = new BroadcastingLivePositionBroadcaster($state, new FleetPlateResolver);

    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000000dd';
    $broadcaster->publish(coverageLivePing($vehicleId, true), true);

    $hot = $state->get($vehicleId);

    expect($hot)->not->toBeNull()
        ->and($hot['status'])->toBe('moving')
        ->and($hot['vehicle_id'])->toBe($vehicleId);
});

it('publica una posición detenida con estado ralentí', function (): void {
    $state = new LiveVehicleState;
    $broadcaster = new BroadcastingLivePositionBroadcaster($state, new FleetPlateResolver);

    $vehicleId = '0192aaaa-bbbb-7ccc-8ddd-0000000000ee';
    // moving=false con encendido => "idle"; cubre la rama statusOf().
    $broadcaster->publish(coverageLivePing($vehicleId, false), false);

    $hot = $state->get($vehicleId);

    expect($hot)->not->toBeNull()
        ->and($hot['status'])->toBe('idle');
});

it('resuelve la placa de un vehículo inexistente como null (degradación con gracia)', function (): void {
    $resolver = new FleetPlateResolver;

    expect($resolver->label('0192aaaa-bbbb-7ccc-8ddd-0000000000ff'))->toBeNull();
});
