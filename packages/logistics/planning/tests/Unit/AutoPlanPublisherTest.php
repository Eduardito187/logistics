<?php

declare(strict_types=1);

use Logistics\Planning\Application\Command\PublishPlan\PublishPlan;
use Logistics\Planning\Application\Service\AutoPlanPublisher;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\Contract\PlanRepository;
use Logistics\Planning\Domain\Contract\RouteLoadProfileProvider;
use Logistics\Planning\Domain\Model\DeliveryPlan;
use Logistics\Planning\Domain\Service\PublishGate;
use Logistics\Planning\Domain\ValueObject\Eta;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\PlanId;
use Logistics\Planning\Domain\ValueObject\PlannedStopId;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

/*
| AutoPlanPublisher (Fase 3.2): toma los planes propuestos del día, los evalúa con la PublishGate y publica
| los aptos (un PublishPlan por plan). Se ejercita con repositorios/proveedores en memoria y un CommandBus
| que captura lo despachado; no toca la base.
*/

const APP_DATE = '2026-11-20';

/**
 * Plan PROPUESTO de un camión con un envío (pasa por Draft → proposeRoute).
 */
function appProposedPlan(string $vehicleId, string $shipmentId): DeliveryPlan
{
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString(APP_DATE), $vehicleId);
    $plan->assignShipment(PlannedStopId::generate(), $shipmentId);
    $plan->proposeRoute(
        [$shipmentId],
        [$shipmentId => Eta::at(new DateTimeImmutable(APP_DATE.'T09:00:00+00:00'), 30)],
    );

    return $plan;
}

/**
 * Plan en BORRADOR (no debe evaluarse ni publicarse).
 */
function appDraftPlan(string $vehicleId): DeliveryPlan
{
    $plan = DeliveryPlan::startDraft(PlanId::generate(), PlanDate::fromString(APP_DATE), $vehicleId);
    $plan->assignShipment(PlannedStopId::generate(), 'shp-draft');

    return $plan;
}

/**
 * @param list<DeliveryPlan> $plans
 */
function appPlans(array $plans): PlanRepository
{
    return new class($plans) implements PlanRepository
    {
        /** @param list<DeliveryPlan> $plans */
        public function __construct(private array $plans) {}

        public function nextIdentity(): PlanId
        {
            return PlanId::generate();
        }

        public function save(DeliveryPlan $plan): void {}

        public function findById(PlanId $id): ?DeliveryPlan
        {
            return null;
        }

        public function findByDate(PlanDate $date): array
        {
            return $this->plans;
        }

        public function idsForGeometryBackfill(?PlanDate $date, bool $all): array
        {
            return [];
        }
    };
}

/**
 * @param list<string> $availableVehicleIds
 */
function appFleet(array $availableVehicleIds): FleetAvailabilityProvider
{
    return new class($availableVehicleIds) implements FleetAvailabilityProvider
    {
        /** @param list<string> $available */
        public function __construct(private array $available) {}

        public function availableFor(PlanDate $date): array
        {
            return array_map(
                static fn (string $id): VehicleCapacitySpec => VehicleCapacitySpec::of($id, 1000.0, 10.0, 'node-1', true),
                $this->available,
            );
        }
    };
}

/**
 * @param array{kg: float, m3: float}      $load
 * @param array{kg: float, m3: float}|null $capacity
 */
function appLoadProfiles(array $load, ?array $capacity): RouteLoadProfileProvider
{
    return new class($load, $capacity) implements RouteLoadProfileProvider
    {
        /**
         * @param array{kg: float, m3: float}      $load
         * @param array{kg: float, m3: float}|null $capacity
         */
        public function __construct(private array $load, private ?array $capacity) {}

        public function loadFor(array $shipmentIds): array
        {
            return $this->load;
        }

        public function capacityFor(string $vehicleId): ?array
        {
            return $this->capacity;
        }
    };
}

function appBus(): CommandBus
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

it('publica los planes propuestos que pasan el gate y cuenta la corrida', function (): void {
    $bus = appBus();
    $publisher = new AutoPlanPublisher(
        appPlans([appProposedPlan('veh-1', 'shp-1')]),
        appFleet(['veh-1']),
        appLoadProfiles(['kg' => 500.0, 'm3' => 5.0], ['kg' => 1000.0, 'm3' => 10.0]),
        new PublishGate,
        $bus,
    );

    $result = $publisher->run(PlanDate::fromString(APP_DATE), PublishThresholds::of(1.0, 0.0, true));

    expect($result->evaluated)->toBe(1)
        ->and($result->published)->toBe(1)
        ->and($result->held)->toBe([])
        ->and($bus->dispatched)->toHaveCount(1)
        ->and($bus->dispatched[0])->toBeInstanceOf(PublishPlan::class);
});

it('retiene (no publica) los planes que no pasan el gate, con sus motivos', function (): void {
    $bus = appBus();
    $publisher = new AutoPlanPublisher(
        appPlans([appProposedPlan('veh-2', 'shp-2')]),
        appFleet([]), // veh-2 NO disponible
        appLoadProfiles(['kg' => 500.0, 'm3' => 5.0], ['kg' => 1000.0, 'm3' => 10.0]),
        new PublishGate,
        $bus,
    );

    $result = $publisher->run(PlanDate::fromString(APP_DATE), PublishThresholds::of(1.0, 0.0, true));

    expect($result->evaluated)->toBe(1)
        ->and($result->published)->toBe(0)
        ->and($result->held)->toHaveCount(1)
        ->and($result->held[0]['vehicle_id'])->toBe('veh-2')
        ->and($bus->dispatched)->toBe([]);
});

it('ignora los planes que no están propuestos (idempotente respecto de publicados/borradores)', function (): void {
    $bus = appBus();
    $publisher = new AutoPlanPublisher(
        appPlans([appDraftPlan('veh-3')]),
        appFleet(['veh-3']),
        appLoadProfiles(['kg' => 100.0, 'm3' => 1.0], ['kg' => 1000.0, 'm3' => 10.0]),
        new PublishGate,
        $bus,
    );

    $result = $publisher->run(PlanDate::fromString(APP_DATE), PublishThresholds::of(1.0, 0.0, true));

    expect($result->evaluated)->toBe(0)
        ->and($result->published)->toBe(0)
        ->and($bus->dispatched)->toBe([]);
});
