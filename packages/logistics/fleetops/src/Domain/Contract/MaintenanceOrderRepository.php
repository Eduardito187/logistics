<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

use DateTimeImmutable;
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
     * Lista órdenes, filtrando opcionalmente por vehículo, estado y rango de la fecha agendada. Orden
     * determinista: fecha agendada desc, id desc. `$limit`/`$offset` bajan LIMIT/OFFSET a SQL al paginar.
     *
     * @return list<MaintenanceOrder>
     */
    public function listBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Cuenta las órdenes que cumplen los MISMOS filtros que `listBy` (total de la paginación).
     */
    public function countBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int;
}
