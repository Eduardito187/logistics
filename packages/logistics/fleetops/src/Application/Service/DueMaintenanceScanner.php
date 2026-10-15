<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Service;

use DateTimeImmutable;
use Logistics\FleetOps\Application\ReadModel\DueMaintenanceView;
use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Contract\VehicleProfileRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\Odometer;

/**
 * Barrido de planes de mantenimiento activos que están por vencer o vencidos a una fecha, resolviendo el
 * odómetro actual de cada vehículo (última lectura registrada; si no hay, el del perfil del vehículo).
 *
 * Reutilizado por la query del tablero (ListDueMaintenance) y por el comando de alerta (AlertDueMaintenance),
 * para que ambos usen EXACTAMENTE el mismo criterio. Es Application: orquesta repos de dominio.
 */
final readonly class DueMaintenanceScanner
{
    public function __construct(
        private MaintenancePlanRepository $plans,
        private OdometerRepository $odometers,
        private VehicleProfileRepository $profiles,
    ) {}

    /**
     * @return list<DueMaintenanceView>
     */
    public function scan(DateTimeImmutable $asOf, int $dueSoonKm, int $dueSoonDays): array
    {
        $results = [];

        foreach ($this->plans->allActive() as $plan) {
            assert($plan instanceof MaintenancePlan);
            $currentKm = $this->currentOdometer($plan->vehicleId());

            if (!$plan->isDue($currentKm, $asOf, $dueSoonKm, $dueSoonDays)) {
                continue;
            }

            $results[] = new DueMaintenanceView(
                $plan->vehicleId(),
                $plan->serviceKind()->value,
                $plan->nextDueKm(),
                $plan->nextDueAt()?->format(DateTimeImmutable::ATOM),
                $currentKm?->km,
                $this->isOverdue($plan, $currentKm, $asOf),
            );
        }

        return $results;
    }

    private function currentOdometer(string $vehicleId): ?Odometer
    {
        $reading = $this->odometers->latestForVehicle($vehicleId);
        if ($reading !== null) {
            return $reading->odometer();
        }

        return $this->profiles->findByVehicle($vehicleId)?->currentOdometer();
    }

    /**
     * Vencido (no solo "por vencer"): el odómetro ya pasó el próximo km, o la fecha de próximo servicio ya pasó.
     */
    private function isOverdue(MaintenancePlan $plan, ?Odometer $currentKm, DateTimeImmutable $asOf): bool
    {
        $nextKm = $plan->nextDueKm();
        if ($nextKm !== null && $currentKm !== null && $currentKm->km >= $nextKm) {
            return true;
        }

        $nextAt = $plan->nextDueAt();

        return $nextAt !== null && $asOf >= $nextAt;
    }
}
