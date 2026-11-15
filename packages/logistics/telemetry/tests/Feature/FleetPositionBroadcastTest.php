<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Event;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Domain\Contract\LivePositionBroadcaster;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\Heading;
use Logistics\Telemetry\Domain\ValueObject\PingId;
use Logistics\Telemetry\Domain\ValueObject\PingSource;
use Logistics\Telemetry\Domain\ValueObject\Speed;
use Logistics\Telemetry\Infrastructure\LiveState\LiveFleetViewFactory;
use Logistics\Telemetry\Infrastructure\LiveState\LiveVehicleState;
use Logistics\Telemetry\Presentation\Broadcasting\FleetPositionChanged;
use Logistics\Telemetry\Presentation\Broadcasting\VehiclePositionUpdated;

/**
 * Evento `fleet.position` (app) emitido desde el fan-out en vivo: lleva el objeto completo de `fleet/live`,
 * sale por los canales privados del tema `fleet` y respeta su propio throttle por vehículo.
 *
 * Store de caché `array` (sin Valkey), directorio de flota de prueba (sin tablas de otros contextos) y
 * eventos interceptados: nada sale del proceso.
 */
const FPB_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000a1';
const FPB_OTHER_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000a2';
const FPB_DRIVER = '0192aaaa-bbbb-7ccc-8ddd-0000000000d1';
const FPB_ASSIGNED_DRIVER = '0192aaaa-bbbb-7ccc-8ddd-0000000000d2';
const FPB_NODE = '0192aaaa-bbbb-7ccc-8ddd-0000000000b1';
const FPB_ROUTE = '0192aaaa-bbbb-7ccc-8ddd-0000000000c1';
const FPB_RECORDED_AT = '2026-11-15T14:00:00+00:00';
const FPB_NOW = '2026-11-15T14:00:03+00:00';

/**
 * Directorio de flota de prueba: FPB_VEHICLE tiene placa, sede en Santa Cruz y chofer asignado; el resto
 * no tiene nada. Cuenta las lecturas para verificar la caché corta.
 */
final class FpbFleetDirectory implements FleetDirectory
{
    public int $reads = 0;

    public function plates(array $vehicleIds): array
    {
        $this->reads++;

        return array_replace(
            array_fill_keys($vehicleIds, null),
            array_intersect_key([FPB_VEHICLE => '1234-ABC'], array_flip($vehicleIds)),
        );
    }

    public function driverNames(array $driverIds): array
    {
        $this->reads++;

        return array_replace(
            array_fill_keys($driverIds, null),
            array_intersect_key(
                [FPB_DRIVER => 'Juan Pérez', FPB_ASSIGNED_DRIVER => 'Ana Rojas'],
                array_flip($driverIds),
            ),
        );
    }

    public function homeBases(array $vehicleIds): array
    {
        $this->reads++;

        return array_intersect_key(
            [FPB_VEHICLE => ['node_id' => FPB_NODE, 'node_name' => 'CD Santa Cruz', 'department' => 'santa_cruz']],
            array_flip($vehicleIds),
        );
    }

    public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array
    {
        $this->reads++;

        return array_replace(
            array_fill_keys($vehicleIds, null),
            array_intersect_key([FPB_VEHICLE => FPB_ASSIGNED_DRIVER], array_flip($vehicleIds)),
        );
    }

    public function vehicleIdsInDepartment(string $department): array
    {
        return $department === 'santa_cruz' ? [FPB_VEHICLE] : [];
    }

    public function retiredVehicleIds(?array $among = null): array
    {
        // Ningún camión de prueba está dado de baja.
        return [];
    }
}

/** Reloj fijo: `updated_at` del evento es la hora del servidor. */
final class FpbClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable(FPB_NOW);
    }
}

beforeEach(function (): void {
    config([
        'logistics-telemetry.live.cache_store' => 'array',
        'logistics-telemetry.live.fleet_throttle_seconds' => 5,
        'logistics-telemetry.live.throttle_seconds' => 3,
    ]);

    $this->directory = new FpbFleetDirectory;
    $this->app->instance(FleetDirectory::class, $this->directory);
    $this->app->instance(Clock::class, new FpbClock);

    Event::fake([FleetPositionChanged::class, VehiclePositionUpdated::class]);
});

/** Ping de dominio mínimo, con o sin chofer/ruta en la lectura. */
function fpbPing(string $vehicleId, ?string $driverId = FPB_DRIVER, ?string $routeId = FPB_ROUTE): VehiclePing
{
    return VehiclePing::record(
        PingId::generate(),
        $vehicleId,
        $driverId,
        null,
        $routeId,
        GeoPoint::of(-17.78, -63.18),
        Speed::kmh(42.5),
        Heading::degrees(90),
        null,
        true,
        null,
        new DateTimeImmutable(FPB_RECORDED_AT),
        new DateTimeImmutable('2026-11-15T14:00:01+00:00'),
        PingSource::App,
        null,
    );
}

/** Instante en la zona de la app, en ISO 8601 (así lo entrega `fleet/live`). */
function fpbInAppZone(string $instant): string
{
    return (new DateTimeImmutable($instant))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format(DATE_ATOM);
}

it('emite fleet.position con el objeto completo de fleet/live por los canales de la ciudad del camión', function (): void {
    app(LivePositionBroadcaster::class)->publish(fpbPing(FPB_VEHICLE), true);

    Event::assertDispatched(FleetPositionChanged::class, function (FleetPositionChanged $event): bool {
        expect($event->broadcastWith())->toBe([
            'vehicle_id' => FPB_VEHICLE,
            'lat' => -17.78,
            'lng' => -63.18,
            'speed_kph' => 42.5,
            'heading' => 90,
            'ignition' => true,
            'moving' => true,
            'route_id' => FPB_ROUTE,
            'recorded_at' => fpbInAppZone(FPB_RECORDED_AT),
            'plate' => '1234-ABC',
            'driver_id' => FPB_DRIVER,
            'base_node_id' => FPB_NODE,
            'base_node_name' => 'CD Santa Cruz',
            'city' => 'santa_cruz',
            'driver_name' => 'Juan Pérez',
            'updated_at' => fpbInAppZone(FPB_NOW),
        ]);

        expect(array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn()))
            ->toBe(['private-fleet.all', 'private-fleet.city.santa_cruz']);

        return true;
    });
});

it('respeta el throttle por vehículo: una ráfaga de pings emite un solo fleet.position', function (): void {
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(fpbPing(FPB_VEHICLE), true);
    $broadcaster->publish(fpbPing(FPB_VEHICLE), true);
    $broadcaster->publish(fpbPing(FPB_VEHICLE), false);

    Event::assertDispatchedTimes(FleetPositionChanged::class, 1);
    // El evento del admin sigue saliendo, con su propio throttle.
    Event::assertDispatchedTimes(VehiclePositionUpdated::class, 1);
});

it('el throttle es por vehículo: otro camión emite su propio evento', function (): void {
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(fpbPing(FPB_VEHICLE), true);
    $broadcaster->publish(fpbPing(FPB_OTHER_VEHICLE), true);

    Event::assertDispatchedTimes(FleetPositionChanged::class, 2);
});

it('la ventana del throttle de fleet.position es propia y configurable', function (): void {
    $state = app(LiveVehicleState::class);

    expect($state->shouldEmitFleet(FPB_VEHICLE))->toBeTrue()
        ->and($state->shouldEmitFleet(FPB_VEHICLE))->toBeFalse()
        // La marca del evento del admin es otra: no se pisan.
        ->and($state->shouldEmit(FPB_VEHICLE))->toBeTrue()
        ->and((int) config('logistics-telemetry.live.fleet_throttle_seconds'))->toBe(5);
});

it('sin sede conocida el evento sale solo por fleet.all y con los datos de referencia en null', function (): void {
    app(LivePositionBroadcaster::class)->publish(fpbPing(FPB_OTHER_VEHICLE, null, null), false);

    Event::assertDispatched(FleetPositionChanged::class, function (FleetPositionChanged $event): bool {
        $payload = $event->broadcastWith();

        expect(array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn()))
            ->toBe(['private-fleet.all'])
            ->and($payload['vehicle_id'])->toBe(FPB_OTHER_VEHICLE)
            ->and($payload['moving'])->toBeFalse()
            ->and($payload['plate'])->toBeNull()
            ->and($payload['driver_id'])->toBeNull()
            ->and($payload['driver_name'])->toBeNull()
            ->and($payload['route_id'])->toBeNull()
            ->and($payload['base_node_id'])->toBeNull()
            ->and($payload['base_node_name'])->toBeNull()
            ->and($payload['city'])->toBeNull();

        return true;
    });
});

it('si la lectura no trae chofer usa el asignado a la ruta del día', function (): void {
    $view = app(LiveFleetViewFactory::class)->fromPing(fpbPing(FPB_VEHICLE, null), true);

    expect($view['driver_id'])->toBe(FPB_ASSIGNED_DRIVER)
        ->and($view['driver_name'])->toBe('Ana Rojas');
});

it('los datos de referencia salen de la caché corta: el segundo ping no vuelve a leer el directorio', function (): void {
    $factory = app(LiveFleetViewFactory::class);

    $first = $factory->fromPing(fpbPing(FPB_VEHICLE), true);
    $readsAfterFirst = $this->directory->reads;
    $second = $factory->fromPing(fpbPing(FPB_VEHICLE), true);

    expect($readsAfterFirst)->toBeGreaterThan(0)
        ->and($this->directory->reads)->toBe($readsAfterFirst)
        ->and($second)->toBe($first);
});

it('se difunde por la cola de avisos, o por la configurada para el fan-out en vivo', function (): void {
    $event = new FleetPositionChanged(app(LiveFleetViewFactory::class)->fromPing(fpbPing(FPB_VEHICLE), true));

    config(['logistics-telemetry.live.broadcast_queue' => null]);
    expect($event->broadcastQueue())
        ->toBe((string) config('observability.metrics.queues.notifications', 'notifications'));

    config(['logistics-telemetry.live.broadcast_queue' => 'telemetry']);
    expect($event->broadcastQueue())->toBe('telemetry');
});
