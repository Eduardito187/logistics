<?php

declare(strict_types=1);

use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Logistics\Telemetry\Application\Command\BindTripToRoute\BindTripToRoute;
use Logistics\Telemetry\Application\Command\BindTripToRoute\BindTripToRouteHandler;
use Logistics\Telemetry\Domain\Contract\TripRepository;
use Logistics\Telemetry\Domain\Model\Trip;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\TripId;
use Logistics\Telemetry\Infrastructure\Outbox\BindTripToRouteOnRouteStarted;

/*
| BindTripToRoute (Fase 3.3): al iniciar la ruta del chofer, el trip activo del vehículo recibe su route_id.
| Cubre el método de dominio (idempotente), el handler (tolerante) y el reactor del outbox. Sin base.
*/

const BTR_VEHICLE = '0192f0a0-0000-7000-8000-0000000veh20';
const BTR_ROUTE = '0192f0a0-0000-7000-8000-0000000rte20';

/**
 * Trip activo de prueba, opcionalmente ya ligado a una ruta.
 */
function btrTrip(?string $routeId = null): Trip
{
    return Trip::start(
        TripId::generate(),
        BTR_VEHICLE,
        null,
        $routeId,
        GeoPoint::of(-17.78, -63.18),
        new DateTimeImmutable('2026-11-20T07:30:00+00:00'),
    );
}

/**
 * Repositorio de trips con (o sin) un trip activo para el vehículo; cuenta los save.
 */
function btrTrips(?Trip $active): TripRepository
{
    return new class($active) implements TripRepository
    {
        public int $saved = 0;

        public function __construct(private ?Trip $active) {}

        public function nextIdentity(): TripId
        {
            return TripId::generate();
        }

        public function save(Trip $trip): void
        {
            $this->saved++;
        }

        public function findById(TripId $id): ?Trip
        {
            return null;
        }

        public function activeForVehicle(string $vehicleId): ?Trip
        {
            return $this->active;
        }

        public function all(): array
        {
            return [];
        }

        public function listBy(?string $vehicleId, ?DateTimeImmutable $day, ?int $limit = null, ?int $offset = null): array
        {
            return [];
        }

        public function countBy(?string $vehicleId, ?DateTimeImmutable $day): int
        {
            return 0;
        }
    };
}

/**
 * CommandBus que captura los comandos despachados.
 */
function btrBus(): CommandBus
{
    return new class implements CommandBus
    {
        /** @var list<Command> */
        public array $dispatched = [];

        public function dispatch(Command $command): void
        {
            $this->dispatched[] = $command;
        }
    };
}

/**
 * Envuelve el hecho execution.driver_route_started en un OutboxMessage.
 *
 * @param array<string, mixed> $payload
 */
function btrOutbox(string $eventName, array $payload): OutboxMessage
{
    $event = new readonly class($eventName, $payload) implements DomainEvent
    {
        /** @param array<string, mixed> $payload */
        public function __construct(
            private string $name,
            private array $payload,
        ) {}

        public function eventId(): Uuid
        {
            return Uuid::generate();
        }

        public function aggregateId(): string
        {
            return BTR_ROUTE;
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-20T07:30:00+00:00');
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'execution.route', new DateTimeImmutable('2026-11-20T07:30:00+00:00'));
}

// ---------------------------------------------------------------------------
// Dominio: Trip::bindToRoute
// ---------------------------------------------------------------------------

it('liga el trip a la ruta solo si no tiene ruta todavía', function (): void {
    $trip = btrTrip();

    expect($trip->bindToRoute(BTR_ROUTE))->toBeTrue()
        ->and($trip->routeId())->toBe(BTR_ROUTE);
});

it('no pisa la ruta de un trip ya ligado', function (): void {
    $trip = btrTrip('otra-ruta');

    expect($trip->bindToRoute(BTR_ROUTE))->toBeFalse()
        ->and($trip->routeId())->toBe('otra-ruta');
});

// ---------------------------------------------------------------------------
// Handler
// ---------------------------------------------------------------------------

it('liga y guarda el trip activo del vehículo', function (): void {
    $trips = btrTrips(btrTrip());
    $handler = new BindTripToRouteHandler($trips);

    $handler->handle(new BindTripToRoute(BTR_VEHICLE, BTR_ROUTE));

    expect($trips->saved)->toBe(1);
});

it('no hace nada si el vehículo no tiene trip activo', function (): void {
    $trips = btrTrips(null);
    $handler = new BindTripToRouteHandler($trips);

    $handler->handle(new BindTripToRoute(BTR_VEHICLE, BTR_ROUTE));

    expect($trips->saved)->toBe(0);
});

it('es idempotente: no vuelve a guardar un trip ya ligado', function (): void {
    $trips = btrTrips(btrTrip(BTR_ROUTE));
    $handler = new BindTripToRouteHandler($trips);

    $handler->handle(new BindTripToRoute(BTR_VEHICLE, BTR_ROUTE));

    expect($trips->saved)->toBe(0);
});

// ---------------------------------------------------------------------------
// Reactor
// ---------------------------------------------------------------------------

it('despacha BindTripToRoute al iniciar la ruta del chofer', function (): void {
    $bus = btrBus();
    $reactor = new BindTripToRouteOnRouteStarted($bus);

    $reactor->dispatch(btrOutbox('execution.driver_route_started', [
        'route_id' => BTR_ROUTE,
        'vehicle_id' => BTR_VEHICLE,
    ]));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(BindTripToRoute::class);

    /** @var BindTripToRoute $command */
    $command = $bus->dispatched[0];
    expect($command->vehicleId)->toBe(BTR_VEHICLE)
        ->and($command->routeId)->toBe(BTR_ROUTE);
});

it('ignora eventos que no son driver_route_started', function (): void {
    $bus = btrBus();
    $reactor = new BindTripToRouteOnRouteStarted($bus);

    $reactor->dispatch(btrOutbox('execution.driver_route_closed', [
        'route_id' => BTR_ROUTE,
        'vehicle_id' => BTR_VEHICLE,
    ]));

    expect($bus->dispatched)->toBe([]);
});
