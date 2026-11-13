<?php

declare(strict_types=1);

use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Infrastructure\Outbox\AssembleRouteOnPlanPublished;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/*
| Reactor AssembleRouteOnPlanPublished (Fase 3.3): al publicarse un plan, arma la ruta del día del chofer.
| Se ejercita con un repositorio de rutas en memoria y un CommandBus que captura lo despachado; no toca DB.
*/

const AROP_VEHICLE = '0192f0a0-0000-7000-8000-0000000veh01';
const AROP_DATE = '2026-11-20';

/**
 * CommandBus que captura los comandos despachados.
 */
function aropBus(): CommandBus
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
 * Repositorio de rutas que responde si ya hay (o no) una ruta para el camión y el día.
 */
function aropRoutes(bool $exists): VehicleRouteRepository
{
    return new class($exists) implements VehicleRouteRepository
    {
        public function __construct(private bool $exists) {}

        public function nextIdentity(): RouteId
        {
            return RouteId::generate();
        }

        public function save(VehicleRoute $route): void {}

        public function findById(RouteId $id): ?VehicleRoute
        {
            return null;
        }

        public function findByVehicleAndDate(string $vehicleId, RouteDate $date): ?VehicleRoute
        {
            if (!$this->exists) {
                return null;
            }

            return VehicleRoute::assemble(
                RouteId::generate(),
                $vehicleId,
                $date,
                [RouteStop::plan(RouteStopId::generate(), 'shp-1', 1)],
            );
        }

        public function findOpenByVehicleId(string $vehicleId): ?VehicleRoute
        {
            return null;
        }

        public function findByShipmentId(string $shipmentId): ?VehicleRoute
        {
            return null;
        }
    };
}

/**
 * Envuelve un hecho planning.plan_published en un OutboxMessage, como lo recibe el reactor.
 *
 * @param array<string, mixed> $payload
 */
function aropOutbox(string $eventName, array $payload): OutboxMessage
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
            return 'plan-1';
        }

        public function eventName(): string
        {
            return $this->name;
        }

        public function occurredAt(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-11-19T21:45:00+00:00');
        }

        public function payload(): array
        {
            return $this->payload;
        }
    };

    return OutboxMessage::fromDomainEvent($event, 'planning.plan', new DateTimeImmutable('2026-11-19T21:45:00+00:00'));
}

it('arma la ruta del día cuando el plan se publica y no existe ruta', function (): void {
    $bus = aropBus();
    $reactor = new AssembleRouteOnPlanPublished($bus, aropRoutes(false));

    $reactor->dispatch(aropOutbox('planning.plan_published', [
        'plan_id' => 'plan-1',
        'plan_date' => AROP_DATE,
        'vehicle_id' => AROP_VEHICLE,
        'stop_count' => 3,
    ]));

    expect($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(AssembleDailyRoute::class);

    /** @var AssembleDailyRoute $command */
    $command = $bus->dispatched[0];
    expect($command->vehicleId)->toBe(AROP_VEHICLE)
        ->and($command->routeDate)->toBe(AROP_DATE)
        ->and($command->stops)->toBe([]);
});

it('es idempotente: no arma otra ruta si ya existe para el camión y el día', function (): void {
    $bus = aropBus();
    $reactor = new AssembleRouteOnPlanPublished($bus, aropRoutes(true));

    $reactor->dispatch(aropOutbox('planning.plan_published', [
        'plan_date' => AROP_DATE,
        'vehicle_id' => AROP_VEHICLE,
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('ignora eventos que no son planning.plan_published', function (): void {
    $bus = aropBus();
    $reactor = new AssembleRouteOnPlanPublished($bus, aropRoutes(false));

    $reactor->dispatch(aropOutbox('planning.plan_proposed', [
        'plan_date' => AROP_DATE,
        'vehicle_id' => AROP_VEHICLE,
    ]));

    expect($bus->dispatched)->toBe([]);
});

it('ignora el hecho sin vehículo o sin fecha', function (): void {
    $bus = aropBus();
    $reactor = new AssembleRouteOnPlanPublished($bus, aropRoutes(false));

    $reactor->dispatch(aropOutbox('planning.plan_published', ['vehicle_id' => AROP_VEHICLE]));

    expect($bus->dispatched)->toBe([]);
});
