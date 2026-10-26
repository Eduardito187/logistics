<?php

declare(strict_types=1);

use Logistics\FleetOps\Application\Service\DueMaintenanceScanner;
use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\Model\VehicleProfile;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;

/**
 * Doble de repositorio de planes: devuelve una lista fija de planes activos. Solo se ejercita `allActive()`.
 */
function fakePlanRepository(MaintenancePlan ...$plans): MaintenancePlanRepository
{
    return new class(array_values($plans)) implements MaintenancePlanRepository
    {
        /** @param list<MaintenancePlan> $plans */
        public function __construct(private readonly array $plans) {}

        public function nextIdentity(): MaintenancePlanId
        {
            return MaintenancePlanId::generate();
        }

        public function save(MaintenancePlan $plan): void {}

        public function findById(MaintenancePlanId $id): ?MaintenancePlan
        {
            return null;
        }

        public function all(): array
        {
            return $this->plans;
        }

        public function forVehicle(string $vehicleId): array
        {
            return [];
        }

        public function allActive(): array
        {
            return $this->plans;
        }
    };
}

/**
 * Doble de repositorio de odómetro: resuelve el km actual de cada vehículo desde un mapa vehicleId → km.
 */
function fakeOdometerRepository(array $kmByVehicle): OdometerRepository
{
    return new class($kmByVehicle) implements OdometerRepository
    {
        /** @param array<string, int> $kmByVehicle */
        public function __construct(private readonly array $kmByVehicle) {}

        public function nextIdentity(): OdometerReadingId
        {
            return OdometerReadingId::generate();
        }

        public function save(OdometerReading $reading): void {}

        public function latestForVehicle(string $vehicleId): ?OdometerReading
        {
            if (!isset($this->kmByVehicle[$vehicleId])) {
                return null;
            }

            return OdometerReading::reconstitute(
                OdometerReadingId::generate(),
                $vehicleId,
                Odometer::km($this->kmByVehicle[$vehicleId]),
                OdometerSource::Telemetry,
                new DateTimeImmutable('2026-10-05T00:00:00+00:00'),
                null,
            );
        }

        public function listBy(?string $vehicleId = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, ?int $limit = null, ?int $offset = null): array
        {
            return [];
        }

        public function countBy(?string $vehicleId = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null): int
        {
            return 0;
        }
    };
}

/**
 * Doble de repositorio de perfiles: no aporta odómetro (siempre nulo) para aislar el criterio por km.
 */
function fakeProfileRepository(): VehicleProfileRepository
{
    return new class implements VehicleProfileRepository
    {
        public function save(VehicleProfile $profile): void {}

        public function findByVehicle(string $vehicleId): ?VehicleProfile
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }
    };
}

/**
 * Construye un plan por km servido a 100 000, cuyo próximo vencimiento queda en 110 000.
 */
function planPorKm(string $vehicleId): MaintenancePlan
{
    $plan = MaintenancePlan::define(MaintenancePlanId::generate(), $vehicleId, ServiceKind::OilChange, 10_000, null);
    $plan->recordServiceDone(Odometer::km(100_000), new DateTimeImmutable('2026-10-01T00:00:00+00:00'));

    return $plan;
}

it('solo devuelve los planes vencidos o por vencer, marcando overdue', function (): void {
    $overduePlan = planPorKm('veh-overdue'); // next_due_km 110000
    $okPlan = planPorKm('veh-ok');           // next_due_km 110000

    $scanner = new DueMaintenanceScanner(
        fakePlanRepository($overduePlan, $okPlan),
        fakeOdometerRepository([
            'veh-overdue' => 115_000, // ya pasó el vencimiento → vencido
            'veh-ok' => 105_000,      // 105000 < 110000 - 500 → vigente
        ]),
        fakeProfileRepository(),
    );

    $results = $scanner->scan(new DateTimeImmutable('2026-10-05T00:00:00+00:00'), 500, 7);

    expect($results)->toHaveCount(1)
        ->and($results[0]->vehicleId)->toBe('veh-overdue')
        ->and($results[0]->serviceKind)->toBe(ServiceKind::OilChange->value)
        ->and($results[0]->nextDueKm)->toBe(110_000)
        ->and($results[0]->currentOdometerKm)->toBe(115_000)
        ->and($results[0]->overdue)->toBeTrue();
});

it('un plan por vencer (dentro del umbral pero no pasado) no se marca como overdue', function (): void {
    $plan = planPorKm('veh-soon'); // next_due_km 110000

    $scanner = new DueMaintenanceScanner(
        fakePlanRepository($plan),
        fakeOdometerRepository(['veh-soon' => 109_700]), // 109700 >= 109500 (umbral) pero < 110000
        fakeProfileRepository(),
    );

    $results = $scanner->scan(new DateTimeImmutable('2026-10-05T00:00:00+00:00'), 500, 7);

    expect($results)->toHaveCount(1)
        ->and($results[0]->overdue)->toBeFalse();
});
