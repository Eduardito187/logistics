<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\FleetOps\Domain\Contract\MaintenanceOrderRepository;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\MaintenanceOrderMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;

/**
 * Implementación Eloquent del repositorio de órdenes de mantenimiento. `save` es un upsert por id.
 */
final readonly class EloquentMaintenanceOrderRepository implements MaintenanceOrderRepository
{
    public function __construct(
        private MaintenanceOrderMapper $mapper = new MaintenanceOrderMapper,
    ) {}

    public function nextIdentity(): MaintenanceOrderId
    {
        return MaintenanceOrderId::generate();
    }

    public function save(MaintenanceOrder $order): void
    {
        $row = $this->mapper->toRow($order);
        MaintenanceOrderRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(MaintenanceOrderId $id): ?MaintenanceOrder
    {
        $record = MaintenanceOrderRecord::query()->find($id->toString());

        return $record instanceof MaintenanceOrderRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(?string $vehicleId = null, ?string $status = null): array
    {
        $query = MaintenanceOrderRecord::query();
        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }

        $records = $query->orderByDesc('scheduled_at')->get();

        return array_values($records->map(fn (MaintenanceOrderRecord $record): MaintenanceOrder => $this->mapper->toDomain($record))->all());
    }
}
