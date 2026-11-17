<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Infrastructure\Outbox\EnsureCrewOnPlanPublished;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Event\DomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Reactor EnsureCrewOnPlanPublished: al publicarse un plan, si el camión NO tiene tripulación ese día, FLOTA
 * arrastra el chofer más reciente de ese camión para que la ruta quede visible. Respeta «un chofer = un camión
 * por día»: no pisa una tripulación ya armada a mano, no inventa chofer si el camión nunca tuvo, y no roba un
 * chofer que ya tiene otro camión ese día. Corre contra PostgreSQL (base logistics_test).
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const ECPP_DAY_PRIOR = '2026-11-19';

const ECPP_DAY_PLAN = '2026-11-20';

/**
 * Envuelve un hecho planning.plan_published en un OutboxMessage, como lo recibe el reactor.
 *
 * @param array<string, mixed> $payload
 */
function ecppOutbox(string $eventName, array $payload): OutboxMessage
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
            return 'plan-ecpp';
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

/**
 * Tripulación vigente de un camión en la fecha del plan.
 */
function ecppCrewOfVehicle(string $vehicleId): ?object
{
    return DB::table('fleet_crews')->where('vehicle_id', $vehicleId)->where('assembled_for', ECPP_DAY_PLAN)->first();
}

it('arrastra el chofer del camión al publicar el plan si ese día no tiene tripulación', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehicleId = (string) Uuid::generate()->value;

    // El camión tenía al chofer el día anterior; el día del plan está libre.
    app(CommandBus::class)->dispatch(new AssembleCrew($driverId, [], $vehicleId, ECPP_DAY_PRIOR));

    app(EnsureCrewOnPlanPublished::class)->dispatch(ecppOutbox('planning.plan_published', [
        'vehicle_id' => $vehicleId,
        'plan_date' => ECPP_DAY_PLAN,
    ]));

    $crew = ecppCrewOfVehicle($vehicleId);

    expect($crew)->not->toBeNull()
        ->and($crew?->driver_id)->toBe($driverId);
});

it('no toca una tripulación ya armada a mano para ese camión y día', function (): void {
    $manualDriver = (string) Uuid::generate()->value;
    $priorDriver = (string) Uuid::generate()->value;
    $vehicleId = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    // Chofer previo del camión, y otro distinto ya armado a mano el día del plan.
    $bus->dispatch(new AssembleCrew($priorDriver, [], $vehicleId, ECPP_DAY_PRIOR));
    $bus->dispatch(new AssembleCrew($manualDriver, [], $vehicleId, ECPP_DAY_PLAN));

    app(EnsureCrewOnPlanPublished::class)->dispatch(ecppOutbox('planning.plan_published', [
        'vehicle_id' => $vehicleId,
        'plan_date' => ECPP_DAY_PLAN,
    ]));

    // Sigue la del armado manual: no la reemplazó por el chofer previo.
    expect(ecppCrewOfVehicle($vehicleId)?->driver_id)->toBe($manualDriver);
});

it('no inventa chofer si el camión nunca tuvo tripulación', function (): void {
    $vehicleId = (string) Uuid::generate()->value;

    app(EnsureCrewOnPlanPublished::class)->dispatch(ecppOutbox('planning.plan_published', [
        'vehicle_id' => $vehicleId,
        'plan_date' => ECPP_DAY_PLAN,
    ]));

    expect(ecppCrewOfVehicle($vehicleId))->toBeNull();
});

it('no roba un chofer que ya tiene otro camión ese día', function (): void {
    $driverId = (string) Uuid::generate()->value;
    $vehiclePrior = (string) Uuid::generate()->value;
    $vehicleOther = (string) Uuid::generate()->value;
    $bus = app(CommandBus::class);

    // El chofer manejó `vehiclePrior` ayer, pero el día del plan ya tiene `vehicleOther`.
    $bus->dispatch(new AssembleCrew($driverId, [], $vehiclePrior, ECPP_DAY_PRIOR));
    $bus->dispatch(new AssembleCrew($driverId, [], $vehicleOther, ECPP_DAY_PLAN));

    // Se publica un plan para `vehiclePrior` ese día: no debe robar al chofer de `vehicleOther`.
    app(EnsureCrewOnPlanPublished::class)->dispatch(ecppOutbox('planning.plan_published', [
        'vehicle_id' => $vehiclePrior,
        'plan_date' => ECPP_DAY_PLAN,
    ]));

    expect(ecppCrewOfVehicle($vehiclePrior))->toBeNull()
        ->and(ecppCrewOfVehicle($vehicleOther)?->driver_id)->toBe($driverId);
});
