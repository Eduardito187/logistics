<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;

/**
 * Puerto de persistencia de planes de mantenimiento preventivo. La implementación vive en Infrastructure.
 */
interface MaintenancePlanRepository
{
    public function nextIdentity(): MaintenancePlanId;

    public function save(MaintenancePlan $plan): void;

    public function findById(MaintenancePlanId $id): ?MaintenancePlan;

    /**
     * Todos los planes (activos e inactivos), para el listado de administración.
     *
     * @return list<MaintenancePlan>
     */
    public function all(): array;

    /**
     * Planes de un vehículo concreto.
     *
     * @return list<MaintenancePlan>
     */
    public function forVehicle(string $vehicleId): array;

    /**
     * Planes activos de toda la flota (para el cálculo de vencimientos que arma el orquestador).
     *
     * @return list<MaintenancePlan>
     */
    public function allActive(): array;
}
