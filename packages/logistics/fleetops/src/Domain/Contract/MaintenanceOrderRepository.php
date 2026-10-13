<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;

/**
 * Puerto de persistencia de órdenes de mantenimiento. La implementación vive en Infrastructure.
 */
interface MaintenanceOrderRepository
{
    public function nextIdentity(): MaintenanceOrderId;

    public function save(MaintenanceOrder $order): void;

    public function findById(MaintenanceOrderId $id): ?MaintenanceOrder;

    /**
     * Lista órdenes, filtrando opcionalmente por vehículo y/o estado.
     *
     * @return list<MaintenanceOrder>
     */
    public function listBy(?string $vehicleId = null, ?string $status = null): array;
}
