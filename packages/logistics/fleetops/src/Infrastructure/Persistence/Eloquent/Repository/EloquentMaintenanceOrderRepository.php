<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
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

    public function listBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        // Orden determinista para paginar: scheduled_at desc con desempate por id (UUID v7).
        $query = $this->filtered($vehicleId, $status, $from, $to)
            ->orderByDesc('scheduled_at')
            ->orderByDesc('id');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        $records = $query->get();

        return array_values($records->map(fn (MaintenanceOrderRecord $record): MaintenanceOrder => $this->mapper->toDomain($record))->all());
    }

    public function countBy(
        ?string $vehicleId = null,
        ?string $status = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): int {
        return $this->filtered($vehicleId, $status, $from, $to)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<MaintenanceOrderRecord>
     */
    private function filtered(
        ?string $vehicleId,
        ?string $status,
        ?DateTimeImmutable $from,
        ?DateTimeImmutable $to,
    ): Builder {
        $query = MaintenanceOrderRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($status !== null) {
            $query->where('status', $status);
        }
        if ($from !== null) {
            $query->where('scheduled_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('scheduled_at', '<=', $to);
        }

        return $query;
    }
}
