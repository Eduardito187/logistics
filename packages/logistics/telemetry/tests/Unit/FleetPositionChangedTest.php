<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Presentation\Broadcasting\FleetPositionChanged;

/**
 * Evento `fleet.position` de la app: canales privados del tema `fleet` (todas las ciudades + la del camión)
 * y, como carga, el objeto de `fleet/live` sin cambios.
 */

/**
 * Objeto de `fleet/live` de ejemplo, armado con el mismo read model que usa la consulta.
 *
 * @return array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string, plate: string|null, driver_id: string|null, driver_name: string|null, updated_at: string|null, base_node_id: string|null, base_node_name: string|null, city: string|null}
 */
function fleetPositionSample(?string $city): array
{
    return VehicleLiveView::fromRow(
        [
            'vehicle_id' => '0192aaaa-bbbb-7ccc-8ddd-000000000001',
            'lat' => -17.78,
            'lng' => -63.18,
            'speed_kph' => 42.5,
            'heading' => 90,
            'ignition' => true,
            'moving' => true,
            'route_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000r1',
            'recorded_at' => new DateTimeImmutable('2026-11-15T14:00:00+00:00'),
            'driver_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000d1',
            'updated_at' => new DateTimeImmutable('2026-11-15T14:00:02+00:00'),
        ],
        '1234-ABC',
        '0192aaaa-bbbb-7ccc-8ddd-0000000000d1',
        'Juan Pérez',
        $city === null ? null : ['node_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000n1', 'node_name' => 'CD Santa Cruz', 'department' => $city],
    )->toArray();
}

it('se llama fleet.position y va por la cola (nunca en el request)', function (): void {
    $event = new FleetPositionChanged(fleetPositionSample('santa_cruz'));

    expect($event->broadcastAs())->toBe('fleet.position')
        ->and(FleetPositionChanged::NAME)->toBe('fleet.position')
        ->and($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->tries)->toBe(1);
});

it('difunde por los canales privados fleet.all y el de la ciudad del camión', function (): void {
    $channels = (new FleetPositionChanged(fleetPositionSample('santa_cruz')))->broadcastOn();

    expect($channels)->each->toBeInstanceOf(PrivateChannel::class)
        ->and(array_map(static fn (Channel $c): string => $c->name, $channels))
        ->toBe(['private-fleet.all', 'private-fleet.city.santa_cruz']);
});

it('sin ciudad conocida difunde solo por fleet.all', function (): void {
    $channels = (new FleetPositionChanged(fleetPositionSample(null)))->broadcastOn();

    expect(array_map(static fn (Channel $c): string => $c->name, $channels))->toBe(['private-fleet.all']);
});

it('la carga es el objeto de fleet/live, con las mismas claves y valores', function (): void {
    $position = fleetPositionSample('santa_cruz');
    $payload = (new FleetPositionChanged($position))->broadcastWith();

    expect($payload)->toBe($position)
        ->and(array_keys($payload))->toBe([
            'vehicle_id', 'lat', 'lng', 'speed_kph', 'heading', 'ignition', 'moving', 'route_id', 'recorded_at',
            'plate', 'driver_id', 'base_node_id', 'base_node_name', 'city', 'driver_name', 'updated_at',
        ])
        ->and($payload['recorded_at'])->toBe('2026-11-15T14:00:00+00:00')
        ->and($payload['updated_at'])->toBe('2026-11-15T14:00:02+00:00')
        ->and($payload['city'])->toBe('santa_cruz');
});
