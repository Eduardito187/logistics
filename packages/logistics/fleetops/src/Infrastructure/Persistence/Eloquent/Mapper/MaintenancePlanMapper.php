<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\MaintenancePlan;
use Logistics\FleetOps\Domain\ValueObject\MaintenancePlanId;
use Logistics\FleetOps\Domain\ValueObject\ServiceKind;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenancePlanRecord;

/**
 * Traduce entre el agregado MaintenancePlan (dominio) y su fila Eloquent. Único punto que conoce ambos.
 *
 * Las columnas datetime vienen como Carbon (cast del Record): se pasan a DateTimeImmutable para el
 * dominio puro.
 */
final class MaintenancePlanMapper
{
    public function toDomain(MaintenancePlanRecord $record): MaintenancePlan
    {
        return MaintenancePlan::reconstitute(
            MaintenancePlanId::fromString($record->id),
            $record->vehicle_id,
            ServiceKind::from($record->service_kind),
            $record->interval_km,
            $record->interval_days,
            $record->last_done_km,
            $record->last_done_at?->toDateTimeImmutable(),
            $record->next_due_km,
            $record->next_due_at?->toDateTimeImmutable(),
            $record->active,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, service_kind: string, interval_km: int|null, interval_days: int|null, last_done_km: int|null, last_done_at: DateTimeImmutable|null, next_due_km: int|null, next_due_at: DateTimeImmutable|null, active: bool}
     */
    public function toRow(MaintenancePlan $plan): array
    {
        return [
            'id' => $plan->id()->toString(),
            'vehicle_id' => $plan->vehicleId(),
            'service_kind' => $plan->serviceKind()->value,
            'interval_km' => $plan->intervalKm(),
            'interval_days' => $plan->intervalDays(),
            'last_done_km' => $plan->lastDoneKm(),
            'last_done_at' => $plan->lastDoneAt(),
            'next_due_km' => $plan->nextDueKm(),
            'next_due_at' => $plan->nextDueAt(),
            'active' => $plan->isActive(),
        ];
    }
}
