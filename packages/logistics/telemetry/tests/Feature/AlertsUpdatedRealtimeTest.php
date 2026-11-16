<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Outbox\Domain\ValueObject\OutboxMessageId;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;
use Logistics\Telemetry\Infrastructure\Realtime\AlertCityReader;
use Logistics\Telemetry\Infrastructure\Realtime\AlertsUpdatedObserver;
use Logistics\Telemetry\Infrastructure\Realtime\BroadcastAlertsUpdated;
use Logistics\Telemetry\Presentation\Broadcasting\AlertsUpdated;

// Señal de tiempo real de las alertas de telemetría: `alerts.updated` por el tema `operations`
// (`operations.all` siempre, y `operations.city.<departamento>` cuando se resuelve la ciudad del camión),
// siempre por la cola y con anti-ráfaga por alerta.
//
// El observador del outbox se ejercita con mensajes armados a mano; el trabajo de difusión se intercepta con
// Bus::fake (qué se encola) y se ejecuta a mano con Event::fake para ver el evento que saldría al socket.
// Nada habla con un servidor de sockets real.

const AUR_AT = '2026-11-10T12:00:00+00:00';

const AUR_RAISED = 'telemetry.alert_raised';

const AUR_ACK = 'telemetry.alert_acknowledged';

const AUR_BROADCAST_DRIVER = 'broadcasting.default';

const AUR_PUSHER = 'pusher';

const AUR_CITY = 'santa_cruz';

const AUR_NOTIFICATIONS_QUEUE = 'observability.metrics.queues.notifications';

beforeEach(function (): void {
    // Caché en memoria para la ventana anti-ráfaga: aislada entre tests aunque el entorno use Valkey.
    config(['cache.default' => 'array']);

    Carbon::setTestNow(Carbon::parse(AUR_AT));

    $this->app->instance(FleetDirectory::class, new AurFleetDirectory);

    Bus::fake([BroadcastAlertsUpdated::class]);
    Event::fake([AlertsUpdated::class]);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

afterEach(function (): void {
    Carbon::setTestNow();
});

/** Activa un transporte de sockets (en los tests el default es `null`, que no emite nada). */
function aurEnableSockets(): void
{
    config([AUR_BROADCAST_DRIVER => AUR_PUSHER]);
}

function aurUuid(): string
{
    return Uuid::generate()->value;
}

/**
 * Mensaje del outbox ya procesado, como lo recibe un observador. El id de la alerta es el AGREGADO (no viaja
 * en la carga de estos eventos).
 *
 * @param array<string, mixed> $payload
 */
function aurMessage(string $eventName, string $alertId, array $payload = []): OutboxMessage
{
    $at = new DateTimeImmutable(AUR_AT);

    return OutboxMessage::restore(
        OutboxMessageId::generate(),
        Uuid::generate(),
        'telemetry.alert',
        $alertId,
        $eventName,
        $payload,
        OutboxStatus::Dispatched,
        0,
        $at,
        $at,
        $at,
        null,
    );
}

/**
 * @param array<string, mixed> $payload
 */
function aurObserve(string $eventName, string $alertId, array $payload = []): void
{
    app(AlertsUpdatedObserver::class)->observe(aurMessage($eventName, $alertId, $payload));
}

/** Ejecuta el trabajo de difusión como lo haría el worker. */
function aurRun(BroadcastAlertsUpdated $job): void
{
    $job->handle(app(AlertCityReader::class));
}

/** Siembra una alerta en `telemetry_alerts` con su vehículo (para resolver la ciudad en el trabajo). */
function aurAlert(string $alertId, string $vehicleId): void
{
    AlertRecord::factory()->create(['id' => $alertId, 'vehicle_id' => $vehicleId]);
}

/**
 * Nombres de canal (con prefijo `private-`) por los que saldría el evento, ordenados.
 *
 * @return list<string>
 */
function aurChannels(AlertsUpdated $event): array
{
    $names = array_map(static fn (PrivateChannel $channel): string => $channel->name, $event->broadcastOn());
    sort($names);

    return $names;
}

/**
 * Directorio de flota de prueba: AUR_VEHICLE tiene sede en Santa Cruz; cualquier otro, sin sede.
 */
final class AurFleetDirectory implements FleetDirectory
{
    public const string VEHICLE = '0192aaaa-bbbb-7ccc-8ddd-0000000000a1';

    public function plates(array $vehicleIds): array
    {
        return array_fill_keys($vehicleIds, null);
    }

    public function driverNames(array $driverIds): array
    {
        return array_fill_keys($driverIds, null);
    }

    public function homeBases(array $vehicleIds): array
    {
        $base = ['node_id' => '0192aaaa-bbbb-7ccc-8ddd-0000000000n1', 'node_name' => 'CD Santa Cruz', 'department' => AUR_CITY];

        return array_intersect_key([self::VEHICLE => $base], array_flip($vehicleIds));
    }

    public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array
    {
        return array_fill_keys($vehicleIds, null);
    }

    public function vehicleIdsInDepartment(string $department): array
    {
        return $department === AUR_CITY ? [self::VEHICLE] : [];
    }

    public function retiredVehicleIds(?array $among = null): array
    {
        return [];
    }
}

// ──────────────────────────── Contrato del evento ────────────────────────────

it('se llama alerts.updated, va por la cola con un solo intento y lleva el id de la alerta', function (): void {
    $alertId = aurUuid();
    $event = new AlertsUpdated($alertId, AUR_CITY);

    expect($event)->toBeInstanceOf(ShouldBroadcast::class)
        ->and($event)->not->toBeInstanceOf(ShouldBroadcastNow::class)
        ->and($event->broadcastAs())->toBe('alerts.updated')
        ->and(AlertsUpdated::NAME)->toBe('alerts.updated')
        ->and($event->tries)->toBe(1)
        ->and($event->timeout)->toBe(15)
        ->and($event->broadcastWith())->toBe(['alert_id' => $alertId])
        ->and(aurChannels($event))->toBe(['private-operations.all', 'private-operations.city.'.AUR_CITY])
        ->and(aurChannels(new AlertsUpdated($alertId)))->toBe(['private-operations.all'])
        ->and((new AlertsUpdated(null))->broadcastWith())->toBe(['alert_id' => null]);
});

// ──────────────────────────── Observador del outbox ────────────────────────────

it('cada hecho de alerta encola la señal, demorada y en la cola de avisos', function (string $eventName): void {
    aurEnableSockets();
    $alertId = aurUuid();

    aurObserve($eventName, $alertId);

    Bus::assertDispatchedTimes(BroadcastAlertsUpdated::class, 1);
    Bus::assertDispatched(
        BroadcastAlertsUpdated::class,
        static fn (BroadcastAlertsUpdated $job): bool => $job->alertId === $alertId
            && $job->delay === 2
            && $job->tries === 1
            && $job->queue === config(AUR_NOTIFICATIONS_QUEUE, 'notifications'),
    );
})->with(AlertsUpdatedObserver::EVENTS);

it('varios hechos seguidos de la misma alerta se avisan una sola vez; otra alerta tiene su propia ventana', function (): void {
    aurEnableSockets();
    $alertId = aurUuid();

    aurObserve(AUR_RAISED, $alertId);
    aurObserve(AUR_ACK, $alertId);

    Bus::assertDispatchedTimes(BroadcastAlertsUpdated::class, 1);

    aurObserve(AUR_RAISED, aurUuid());

    Bus::assertDispatchedTimes(BroadcastAlertsUpdated::class, 2);
});

it('un hecho ajeno a las alertas no encola nada', function (string $eventName): void {
    aurEnableSockets();

    aurObserve($eventName, aurUuid());

    Bus::assertNotDispatched(BroadcastAlertsUpdated::class);
})->with(['telemetry.vehicle_speeding_sustained', 'telemetry.vehicle_route_deviated', 'fleet.vehicle_status_changed', 'tracking.status_changed']);

it('la ventana anti-ráfaga y la demora salen de la config', function (): void {
    aurEnableSockets();
    config(['logistics-telemetry.realtime.alerts.debounce_seconds' => 7]);

    aurObserve(AUR_RAISED, aurUuid());

    Bus::assertDispatched(BroadcastAlertsUpdated::class, static fn (BroadcastAlertsUpdated $job): bool => $job->delay === 7);
});

it('sin transporte de tiempo real el observador no encola', function (?string $driver, bool $enabled): void {
    config([AUR_BROADCAST_DRIVER => $driver, 'logistics-telemetry.realtime.alerts.enabled' => $enabled]);

    aurObserve(AUR_RAISED, aurUuid());

    Bus::assertNotDispatched(BroadcastAlertsUpdated::class);
})->with([
    'sin transporte' => [null, true],
    'transporte null' => ['null', true],
    'transporte log' => ['log', true],
    'señal apagada' => [AUR_PUSHER, false],
]);

it('el observador nunca lanza ni encola con un id de alerta mal formado', function (string $alertId): void {
    aurEnableSockets();

    aurObserve(AUR_RAISED, $alertId);

    Bus::assertNotDispatched(BroadcastAlertsUpdated::class);
})->with([
    'vacío' => [''],
    'id que no es UUID' => ['A-0001'],
]);

it('el observador traga un fallo del caché sin propagarlo', function (): void {
    aurEnableSockets();
    Cache::shouldReceive('add')->once()->andThrow(new AurCacheDownException('caché caído'));

    aurObserve(AUR_RAISED, aurUuid());

    Bus::assertNotDispatched(BroadcastAlertsUpdated::class);
});

// ──────────────────────────── Trabajo de difusión ────────────────────────────

it('emite alerts.updated a operations.all y a la ciudad del camión de la alerta', function (): void {
    aurEnableSockets();
    $alertId = aurUuid();
    aurAlert($alertId, AurFleetDirectory::VEHICLE);

    aurRun(new BroadcastAlertsUpdated($alertId));

    Event::assertDispatchedTimes(AlertsUpdated::class, 1);
    Event::assertDispatched(
        AlertsUpdated::class,
        static fn (AlertsUpdated $event): bool => $event->broadcastWith() === ['alert_id' => $alertId]
            && aurChannels($event) === ['private-operations.all', 'private-operations.city.'.AUR_CITY],
    );
});

it('sin ciudad resoluble (alerta sin fila o vehículo sin sede) emite solo por operations.all', function (): void {
    aurEnableSockets();
    $alertId = aurUuid();
    aurAlert($alertId, aurUuid());

    aurRun(new BroadcastAlertsUpdated($alertId));
    // Una alerta que ni siquiera está en la base tampoco rompe: sale solo por el canal general.
    aurRun(new BroadcastAlertsUpdated(aurUuid()));

    Event::assertDispatchedTimes(AlertsUpdated::class, 2);
    Event::assertDispatched(
        AlertsUpdated::class,
        static fn (AlertsUpdated $event): bool => aurChannels($event) === ['private-operations.all'],
    );
});

it('sin transporte de tiempo real el trabajo no emite', function (): void {
    $alertId = aurUuid();
    aurAlert($alertId, AurFleetDirectory::VEHICLE);

    aurRun(new BroadcastAlertsUpdated($alertId));

    Event::assertNotDispatched(AlertsUpdated::class);
});

/**
 * Falla simulada del caché, para comprobar que el observador no la propaga.
 */
final class AurCacheDownException extends RuntimeException {}
