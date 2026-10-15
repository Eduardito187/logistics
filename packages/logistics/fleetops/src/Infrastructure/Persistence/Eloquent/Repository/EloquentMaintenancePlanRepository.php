<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\FleetOps\Domain\Contract\MaintenancePlanRepository;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\MaintenancePlanMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;

/**
 * Implementación Eloquent del repositorio de planes de mantenimiento. `save` es un upsert por id.
 */
final readonly class EloquentMaintenancePlanRepository implements MaintenancePlanRepository
{
    public function __construct(
        private MaintenancePlanMapper $mapper = new MaintenancePlanMapper,
    ) {}

    public function nextIdentity(): MaintenancePlanId
    {
        return MaintenancePlanId::generate();
    }

    public function save(MaintenancePlan $plan): void
    {
        $row = $this->mapper->toRow($plan);
        MaintenancePlanRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(MaintenancePlanId $id): ?MaintenancePlan
    {
        $record = MaintenancePlanRecord::query()->find($id->toString());

        return $record instanceof MaintenancePlanRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        $records = MaintenancePlanRecord::query()->get();

        return $this->mapAll(array_values($records->all()));
    }

    public function forVehicle(string $vehicleId): array
    {
        $records = MaintenancePlanRecord::query()->where('vehicle_id', $vehicleId)->get();

        return $this->mapAll(array_values($records->all()));
    }

    public function allActive(): array
    {
        $records = MaintenancePlanRecord::query()->where('active', true)->get();

        return $this->mapAll(array_values($records->all()));
    }

    /**
     * @param  list<MaintenancePlanRecord> $records
     * @return list<MaintenancePlan>
     */
    private function mapAll(array $records): array
    {
        return array_values(array_map(
            fn (MaintenancePlanRecord $record): MaintenancePlan => $this->mapper->toDomain($record),
            $records,
        ));
    }
}
