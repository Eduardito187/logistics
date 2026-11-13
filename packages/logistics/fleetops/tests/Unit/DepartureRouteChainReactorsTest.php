<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Command\CloseDeparture\CloseDeparture;
use Logistics\FleetOps\Application\Command\RegisterDeparture\RegisterDeparture;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\Load;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Infrastructure\Outbox\CloseDepartureOnRouteClosed;
use Logistics\FleetOps\Infrastructure\Outbox\OpenDepartureOnRouteStarted;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/*
| Reactores de la cadena ruta → salida de flota (Fase 3.3): abren la salida al iniciar la ruta del chofer y
| la cierran al cerrarla. Se ejercitan con un repositorio de salidas en memoria y un CommandBus que captura
| lo despachado; no tocan la base.
*/

const DRC_VEHICLE = '0192f0a0-0000-7000-8000-0000000veh09';
const DRC_ROUTE = '0192f0a0-0000-7000-8000-0000000rte09';

/**
 * CommandBus que captura los comandos despachados.
 */
function drcBus(): CommandBus
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
 * Repositorio de salidas con (o sin) una salida abierta para el vehículo.
 */
function drcDepartures(?Departure $open): DepartureRepository
{
    return new class($open) implements DepartureRepository
    {
        public function __construct(private ?Departure $open) {}

        public function nextIdentity(): DepartureId
        {
            return DepartureId::generate();
        }

        public function save(Departure $departure): void {}

        public function findById(DepartureId $id): ?Departure
        {
            return null;
        }

        public function listBy(
            ?string $vehicleId = null,
            ?string $status = null,
            ?DateTimeImmutable $from = null,
            ?DateTimeImmutable $to = null,
            ?int $limit = null,
            ?int $offset = null,
        ): array {
            return [];
        }

        public function countBy(
            ?string $vehicleId = null,
            ?string $status = null,
            ?DateTimeImmutable $from = null,
            ?DateTimeImmutable $to = null,
        ): int {
            return 0;
        }

        public function openForVehicle(string $vehicleId): ?Departure
        {
            return $this->open;
        }
    };
}

/**
 * Salida en ruta (Out) de prueba con el odómetro de salida dado.
 */
function drcOpenDeparture(int $odometerOutKm): Departure
{
    return Departure::register(
        DepartureId::generate(),
        DRC_VEHICLE,
        null,
        [],
        null,
        DRC_ROUTE,
        Load::empty(),
        Odometer::km($odometerOutKm),
        new DateTimeImmutable('2026-11-20T07:30:00+00:00'),
        null,
    );
}

/**
 * Envuelve un hecho de ejecución de ruta en un OutboxMessage, como lo recibe el reactor.
 *
 * @param array<string, mixed> $payload
 */
function drcOutbox(string $eventName, array $payload): OutboxMessage
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
            return DRC_ROUTE;
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
// OpenDepartureOnRouteStarted
// ---------------------------------------------------------------------------

it('abre la salida de flota al iniciar la ruta del chofer', function (): void {
    $bus = drcBus();
    $reactor = new OpenDepartureOnRouteStarted($bus, drcDepartures(null));

    $reactor->dispatch(drcOutbox('execution.driver_route_started', [
        'route_id' => DRC_ROUTE,
        'vehicle_id' => DRC_VEHICLE,
        'odometer_km' => 12345.6,
        'occurred_at' => '2026-11-20T07:30:00+00:00',
    ]));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(RegisterDeparture::class);

    /** @var RegisterDeparture $command */
    $command = $bus->dispatched[0];
    expect($command->vehicleId)->toBe(DRC_VEHICLE)
        ->and($command->routeRef)->toBe(DRC_ROUTE)
        ->and($command->odometerOutKm)->toBe(12346)
        ->and($command->departedAt)->toBe('2026-11-20T07:30:00+00:00');
});

it('es idempotente: no abre otra salida si ya hay una en ruta', function (): void {
    $bus = drcBus();
    $reactor = new OpenDepartureOnRouteStarted($bus, drcDepartures(drcOpenDeparture(12000)));

    $reactor->dispatch(drcOutbox('execution.driver_route_started', [
        'route_id' => DRC_ROUTE,
        'vehicle_id' => DRC_VEHICLE,
        'occurred_at' => '2026-11-20T07:30:00+00:00',
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('ignora eventos que no son driver_route_started al abrir', function (): void {
    $bus = drcBus();
    $reactor = new OpenDepartureOnRouteStarted($bus, drcDepartures(null));

    $reactor->dispatch(drcOutbox('execution.driver_route_closed', [
        'vehicle_id' => DRC_VEHICLE,
        'occurred_at' => '2026-11-20T07:30:00+00:00',
    ]));

    expect($bus->dispatched)->toBe([]);
});

// ---------------------------------------------------------------------------
// CloseDepartureOnRouteClosed
// ---------------------------------------------------------------------------

it('cierra la salida abierta al cerrar la ruta, con el odómetro de regreso', function (): void {
    $bus = drcBus();
    $departure = drcOpenDeparture(12000);
    $reactor = new CloseDepartureOnRouteClosed($bus, drcDepartures($departure));

    $reactor->dispatch(drcOutbox('execution.driver_route_closed', [
        'route_id' => DRC_ROUTE,
        'vehicle_id' => DRC_VEHICLE,
        'odometer_km' => 12210.0,
        'occurred_at' => '2026-11-20T18:00:00+00:00',
    ]));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(CloseDeparture::class);

    /** @var CloseDeparture $command */
    $command = $bus->dispatched[0];
    expect($command->departureId)->toBe($departure->id()->toString())
        ->and($command->odometerInKm)->toBe(12210)
        ->and($command->returnedAt)->toBe('2026-11-20T18:00:00+00:00');
});

it('no deja que el odómetro de regreso sea menor al de salida', function (): void {
    $bus = drcBus();
    $reactor = new CloseDepartureOnRouteClosed($bus, drcDepartures(drcOpenDeparture(12000)));

    $reactor->dispatch(drcOutbox('execution.driver_route_closed', [
        'vehicle_id' => DRC_VEHICLE,
        'odometer_km' => 500.0,
        'occurred_at' => '2026-11-20T18:00:00+00:00',
    ]));

    /** @var CloseDeparture $command */
    $command = $bus->dispatched[0];
    expect($command->odometerInKm)->toBe(12000);
});

it('es idempotente: no cierra nada si no hay salida abierta', function (): void {
    $bus = drcBus();
    $reactor = new CloseDepartureOnRouteClosed($bus, drcDepartures(null));

    $reactor->dispatch(drcOutbox('execution.driver_route_closed', [
        'vehicle_id' => DRC_VEHICLE,
        'occurred_at' => '2026-11-20T18:00:00+00:00',
    ]));

    expect($bus->dispatched)->toBe([]);
});
