<?php

declare(strict_types=1);

use Illuminate\Broadcasting\Channel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;
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
use Logistics\Telemetry\Infrastructure\LiveState\TrailingFleetEmitter;
use Logistics\Telemetry\Infrastructure\Outbox\BroadcastFleetRemovedOnVehicleRetired;
use Logistics\Telemetry\Infrastructure\Queue\EmitTrailingFleetPositionJob;
use Logistics\Telemetry\Presentation\Broadcasting\FleetPositionChanged;
use Logistics\Telemetry\Presentation\Broadcasting\FleetVehicleRemoved;
use Logistics\Telemetry\Presentation\Broadcasting\VehiclePositionUpdated;

/**
 * Pendientes de "Flota en vivo" del lado del tiempo real:
 *
 *  - emisión final de `fleet.position` tras el throttle (la última lectura descartada sale al cerrar la
 *    ventana, sin duplicar y con un solo trabajo en espera por vehículo);
 *  - baja de un camión en flota → limpieza del estado caliente + `fleet.removed`.
 *
 * Store de caché `array` (sin Valkey), directorio de flota de prueba (sin tablas de otros contextos) y
 * eventos interceptados: nada sale del proceso. La ventana se "cierra" adelantando el reloj de la prueba.
 */
const FLR_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000e1';
const FLR_RETIRED_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000e2';
const FLR_HOMELESS_VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000e3';
const FLR_NODE = '0192aaaa-bbbb-7ccc-8ddd-0000000000e9';
const FLR_T0 = '2026-11-15T14:00:00+00:00';
const FLR_T1 = '2026-11-15T14:00:01+00:00';
const FLR_T2 = '2026-11-15T14:00:02+00:00';
const FLR_T7 = '2026-11-15T14:00:07+00:00';

/**
 * Directorio de flota de prueba: FLR_VEHICLE y FLR_RETIRED_VEHICLE tienen sede en Santa Cruz;
 * FLR_RETIRED_VEHICLE está dado de baja (mientras siga en `$retired`); FLR_HOMELESS_VEHICLE no tiene sede.
 */
final class FlrFleetDirectory implements FleetDirectory
{
    /** @var list<string> Vehículos dados de baja (se puede cambiar en la prueba). */
    public array $retired = [FLR_RETIRED_VEHICLE];

    public function plates(array $vehicleIds): array
    {
        return array_replace(
            array_fill_keys($vehicleIds, null),
            array_intersect_key([FLR_VEHICLE => '1234-ABC', FLR_RETIRED_VEHICLE => '9999-ZZZ'], array_flip($vehicleIds)),
        );
    }

    public function driverNames(array $driverIds): array
    {
        return array_fill_keys($driverIds, null);
    }

    public function homeBases(array $vehicleIds): array
    {
        $base = ['node_id' => FLR_NODE, 'node_name' => 'CD Santa Cruz', 'department' => 'santa_cruz'];

        return array_intersect_key(
            [FLR_VEHICLE => $base, FLR_RETIRED_VEHICLE => $base],
            array_flip($vehicleIds),
        );
    }

    public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array
    {
        return array_fill_keys($vehicleIds, null);
    }

    public function vehicleIdsInDepartment(string $department): array
    {
        return $department === 'santa_cruz' ? [FLR_VEHICLE, FLR_RETIRED_VEHICLE] : [];
    }

    public function retiredVehicleIds(?array $among = null): array
    {
        return $among === null
            ? $this->retired
            : array_values(array_intersect($this->retired, $among));
    }
}

/** Reloj fijo para `updated_at` del objeto difundido. */
final class FlrClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-15T14:00:10+00:00');
    }
}

beforeEach(function (): void {
    config([
        'logistics-telemetry.live.cache_store' => 'array',
        'logistics-telemetry.live.fleet_throttle_seconds' => 5,
        'logistics-telemetry.live.throttle_seconds' => 3,
        'logistics-telemetry.live.trailing_emit' => true,
        'logistics-telemetry.live.retired_statuses' => ['inactive'],
        'logistics-telemetry.live.broadcast_queue' => null,
    ]);

    $this->directory = new FlrFleetDirectory;
    $this->app->instance(FleetDirectory::class, $this->directory);
    $this->app->instance(Clock::class, new FlrClock);

    Event::fake([FleetPositionChanged::class, FleetVehicleRemoved::class, VehiclePositionUpdated::class]);
});

/** Ping de dominio mínimo en un instante y una latitud dados. */
function flrPing(string $recordedAt, float $lat, string $vehicleId = FLR_VEHICLE): VehiclePing
{
    return VehiclePing::record(
        PingId::generate(),
        $vehicleId,
        null,
        null,
        null,
        GeoPoint::of($lat, -63.18),
        Speed::kmh(20.0),
        Heading::degrees(90),
        null,
        true,
        null,
        new DateTimeImmutable($recordedAt),
        new DateTimeImmutable($recordedAt),
        PingSource::App,
        null,
    );
}

/**
 * Latitudes de los `fleet.position` difundidos hasta ahora, en orden.
 *
 * @return list<float>
 */
function flrEmittedLats(): array
{
    $lats = [];
    foreach (Event::dispatched(FleetPositionChanged::class) as $dispatched) {
        /** @var FleetPositionChanged $event */
        $event = $dispatched[0];
        $lats[] = $event->position['lat'];
    }

    return $lats;
}

/**
 * Envuelve un hecho de dominio de fleet en un mensaje del outbox (el id del vehículo es el agregado).
 *
 * @param array<string, mixed> $payload
 */
function flrOutbox(string $eventName, string $vehicleId, array $payload): OutboxMessage
{
    $event = new readonly class($eventName, $vehicleId, $payload) implements DomainEvent
    {
        /** @param array<string, mixed> $payload */
        public function __construct(
            private string $name,
            private string $vehicleId,
            private array $payload,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return $this->vehicleId;
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable(FLR_T0);
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'fleet.vehicle', new DateTimeImmutable(FLR_T0));
}

// ---------------------------------------------------------------------------
// Emisión final tras el throttle
// ---------------------------------------------------------------------------

it('la lectura descartada queda pendiente y se programa un solo trabajo por vehículo', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);
    $broadcaster->publish(flrPing(FLR_T2, -17.72), false);

    expect(flrEmittedLats())->toBe([-17.70])
        ->and(app(LiveVehicleState::class)->pendingFleet(FLR_VEHICLE)['lat'] ?? null)->toBe(-17.72)
        ->and(app(LiveVehicleState::class)->pendingFleet(FLR_VEHICLE)['moving'] ?? null)->toBeFalse();

    Bus::assertDispatchedTimes(EmitTrailingFleetPositionJob::class, 1);
    Bus::assertDispatched(
        EmitTrailingFleetPositionJob::class,
        static fn (EmitTrailingFleetPositionJob $job): bool => $job->vehicleId() === FLR_VEHICLE
            && $job->delay === 5
            && $job->queue === (string) config('observability.metrics.queues.notifications', 'notifications'),
    );
});

it('al cerrar la ventana emite la última lectura descartada, una sola vez', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $broadcaster = app(LivePositionBroadcaster::class);
    $emitter = app(TrailingFleetEmitter::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);
    $broadcaster->publish(flrPing(FLR_T2, -17.72), false);

    $this->travel(6)->seconds();

    expect($emitter->flush(FLR_VEHICLE))->toBeTrue()
        ->and(flrEmittedLats())->toBe([-17.70, -17.72]);

    /** @var FleetPositionChanged $last */
    $last = Event::dispatched(FleetPositionChanged::class)->last()[0];
    $expectedRecordedAt = (new DateTimeImmutable(FLR_T2))
        ->setTimezone(new DateTimeZone(date_default_timezone_get()))
        ->format(DATE_ATOM);

    expect($last->position['vehicle_id'])->toBe(FLR_VEHICLE)
        ->and($last->position['moving'])->toBeFalse()
        ->and($last->position['plate'])->toBe('1234-ABC')
        ->and($last->position['city'])->toBe('santa_cruz')
        ->and($last->position['recorded_at'])->toBe($expectedRecordedAt)
        ->and(array_map(static fn (Channel $c): string => $c->name, $last->broadcastOn()))
        ->toBe(['private-fleet.all', 'private-fleet.city.santa_cruz']);

    // Otra corrida (copia del trabajo, o ventana siguiente) no repite la posición.
    $this->travel(6)->seconds();

    expect($emitter->flush(FLR_VEHICLE))->toBeFalse()
        ->and(flrEmittedLats())->toBe([-17.70, -17.72]);
});

it('no duplica si una lectura más nueva ya salió por el camino normal', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);

    $this->travel(6)->seconds();
    $broadcaster->publish(flrPing(FLR_T7, -17.77), true);

    expect(flrEmittedLats())->toBe([-17.70, -17.77])
        ->and(app(TrailingFleetEmitter::class)->flush(FLR_VEHICLE))->toBeFalse()
        ->and(flrEmittedLats())->toBe([-17.70, -17.77]);
});

it('respeta el throttle: con la ventana todavía tomada no emite y se reprograma una vez', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $broadcaster = app(LivePositionBroadcaster::class);
    $emitter = app(TrailingFleetEmitter::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);
    Bus::assertDispatchedTimes(EmitTrailingFleetPositionJob::class, 1);

    // El trabajo corre antes de que venza la ventana: no emite y deja otro en espera.
    expect($emitter->flush(FLR_VEHICLE))->toBeFalse()
        ->and(flrEmittedLats())->toBe([-17.70]);
    Bus::assertDispatchedTimes(EmitTrailingFleetPositionJob::class, 2);

    // Sin permiso para reprogramar (corrió sin haber esperado): no encola nada más.
    expect($emitter->flush(FLR_VEHICLE, false))->toBeFalse();
    Bus::assertDispatchedTimes(EmitTrailingFleetPositionJob::class, 2);

    $this->travel(6)->seconds();

    expect($emitter->flush(FLR_VEHICLE))->toBeTrue()
        ->and(flrEmittedLats())->toBe([-17.70, -17.71]);
});

it('con la cola sync (sin espera real) no encadena trabajos ni emite de más', function (): void {
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);
    $broadcaster->publish(flrPing(FLR_T2, -17.72), true);

    expect(flrEmittedLats())->toBe([-17.70])
        ->and(app(LiveVehicleState::class)->pendingFleet(FLR_VEHICLE)['lat'] ?? null)->toBe(-17.72);
});

it('la emisión final se apaga por configuración', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    config(['logistics-telemetry.live.trailing_emit' => false]);
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);

    Bus::assertNotDispatched(EmitTrailingFleetPositionJob::class);
    expect(app(LiveVehicleState::class)->pendingFleet(FLR_VEHICLE))->toBeNull();
});

it('un camión dado de baja no difunde fleet.position ni por el camino normal ni por la emisión final', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70, FLR_RETIRED_VEHICLE), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71, FLR_RETIRED_VEHICLE), true);

    $this->travel(6)->seconds();

    expect(app(TrailingFleetEmitter::class)->flush(FLR_RETIRED_VEHICLE))->toBeFalse();
    Event::assertNotDispatched(FleetPositionChanged::class);
});

// ---------------------------------------------------------------------------
// Baja del camión en flota → fleet.removed
// ---------------------------------------------------------------------------

it('al darse de baja el vehículo limpia su estado caliente y difunde fleet.removed por su ciudad', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $state = app(LiveVehicleState::class);
    $broadcaster = app(LivePositionBroadcaster::class);

    $broadcaster->publish(flrPing(FLR_T0, -17.70), true);
    $broadcaster->publish(flrPing(FLR_T1, -17.71), true);

    expect($state->get(FLR_VEHICLE))->not->toBeNull()
        ->and($state->pendingFleet(FLR_VEHICLE))->not->toBeNull();

    app(BroadcastFleetRemovedOnVehicleRetired::class)->dispatch(
        flrOutbox('fleet.vehicle_status_changed', FLR_VEHICLE, ['from' => 'available', 'to' => 'inactive']),
    );

    expect($state->get(FLR_VEHICLE))->toBeNull()
        ->and($state->pendingFleet(FLR_VEHICLE))->toBeNull()
        ->and($state->lastFleetEmittedAt(FLR_VEHICLE))->toBeNull();

    Event::assertDispatchedTimes(FleetVehicleRemoved::class, 1);
    Event::assertDispatched(FleetVehicleRemoved::class, function (FleetVehicleRemoved $event): bool {
        expect($event->broadcastAs())->toBe('fleet.removed')
            ->and($event->broadcastWith())->toBe(['vehicle_id' => FLR_VEHICLE])
            ->and(array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn()))
            ->toBe(['private-fleet.all', 'private-fleet.city.santa_cruz']);

        return true;
    });
});

it('sin sede conocida fleet.removed sale solo por fleet.all, y el estado se compara sin importar mayúsculas', function (): void {
    app(BroadcastFleetRemovedOnVehicleRetired::class)->dispatch(
        flrOutbox('fleet.vehicle_status_changed', FLR_HOMELESS_VEHICLE, ['from' => 'maintenance', 'to' => 'INACTIVE']),
    );

    Event::assertDispatched(FleetVehicleRemoved::class, function (FleetVehicleRemoved $event): bool {
        expect($event->vehicleId)->toBe(FLR_HOMELESS_VEHICLE)
            ->and(array_map(static fn (Channel $c): string => $c->name, $event->broadcastOn()))
            ->toBe(['private-fleet.all']);

        return true;
    });
});

it('un cambio de estado que no es una baja no quita el camión ni toca su estado caliente', function (): void {
    Bus::fake([EmitTrailingFleetPositionJob::class]);
    $state = app(LiveVehicleState::class);
    app(LivePositionBroadcaster::class)->publish(flrPing(FLR_T0, -17.70), true);

    $reactor = app(BroadcastFleetRemovedOnVehicleRetired::class);
    $reactor->dispatch(flrOutbox('fleet.vehicle_status_changed', FLR_VEHICLE, ['from' => 'available', 'to' => 'maintenance']));
    // Vuelta de una baja: no es una baja nueva.
    $reactor->dispatch(flrOutbox('fleet.vehicle_status_changed', FLR_VEHICLE, ['from' => 'inactive', 'to' => 'available']));
    // Otro hecho de fleet, aunque traiga `to`.
    $reactor->dispatch(flrOutbox('fleet.vehicle_registered', FLR_VEHICLE, ['to' => 'inactive']));

    Event::assertNotDispatched(FleetVehicleRemoved::class);
    expect($state->get(FLR_VEHICLE))->not->toBeNull();
});

it('la marca de baja del vehículo se refresca al volver de una baja, sin esperar a la caché', function (): void {
    $views = app(LiveFleetViewFactory::class);

    // La referencia queda en caché con la baja vigente.
    expect($views->isRetired(FLR_RETIRED_VEHICLE))->toBeTrue();

    // Flota lo reactiva: el directorio ya no lo lista como dado de baja.
    $this->directory->retired = [];
    expect($views->isRetired(FLR_RETIRED_VEHICLE))->toBeTrue();

    app(BroadcastFleetRemovedOnVehicleRetired::class)->dispatch(
        flrOutbox('fleet.vehicle_status_changed', FLR_RETIRED_VEHICLE, ['from' => 'inactive', 'to' => 'available']),
    );

    expect($views->isRetired(FLR_RETIRED_VEHICLE))->toBeFalse();
    Event::assertNotDispatched(FleetVehicleRemoved::class);
});
