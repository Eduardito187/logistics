<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Logistics\Telemetry\Presentation\Broadcasting\VehiclePositionUpdated;

/**
 * El fan-out en vivo se difunde al canal GLOBAL `fleet` (torre) y, cuando se conoce el nodo base del
 * vehículo, a un canal ACOTADO por nodo `fleet.node.{id}` (C8), además del canal por vehículo.
 *
 * Los tres son PRIVADOS: el socket es alcanzable desde internet y el canal global lleva la flota entera.
 */
it('difunde al canal global, al acotado por nodo y al del vehículo cuando hay nodo base', function (): void {
    $event = new VehiclePositionUpdated(
        vehicleId: 'veh-1',
        plate: 'ABC-123',
        lat: -16.5,
        lng: -68.15,
        status: 'moving',
        occurredAt: '2026-11-06T12:00:00+00:00',
        homeBaseNodeId: 'node-9',
    );

    $names = array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn());

    expect($names)->toBe(['private-fleet', 'private-fleet.node.node-9', 'private-vehicle.veh-1']);
});

it('sin nodo base resuelto, difunde solo al canal global y al del vehículo (sin canal acotado)', function (): void {
    $event = new VehiclePositionUpdated(
        vehicleId: 'veh-2',
        plate: null,
        lat: -16.5,
        lng: -68.15,
        status: 'idle',
        occurredAt: '2026-11-06T12:00:00+00:00',
        homeBaseNodeId: null,
    );

    $names = array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn());

    expect($names)->toBe(['private-fleet', 'private-vehicle.veh-2']);
});

it('el canal global de la flota es privado: nadie lo escucha sin autorizarse', function (): void {
    $event = new VehiclePositionUpdated(
        vehicleId: 'veh-3',
        plate: 'XYZ-789',
        lat: -17.78,
        lng: -63.18,
        status: 'stopped',
        occurredAt: '2026-11-06T12:00:00+00:00',
        homeBaseNodeId: 'node-1',
    );

    $channels = $event->broadcastOn();

    expect($channels)->each->toBeInstanceOf(PrivateChannel::class)
        ->and($channels[0]->name)->toBe('private-fleet')
        ->and($event->broadcastAs())->toBe('vehicle.position');
});
