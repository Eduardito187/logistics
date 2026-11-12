<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\VehicleFitnessLog;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleFitnessLogModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Bitácora de aptitud en Eloquent: solo inserta (append-only), nunca actualiza ni borra.
 */
final readonly class EloquentVehicleFitnessLog implements VehicleFitnessLog
{
    public function append(
        string $vehicleId,
        string $action,
        DateTimeImmutable $occurredAt,
        ?string $reason,
        array $failedItems,
        ?string $inspectionId,
        ?string $actorId,
        ?string $actorLabel,
    ): void {
        VehicleFitnessLogModel::query()->create([
            'id' => Uuid::generate()->value,
            'vehicle_id' => $vehicleId,
            'action' => $action,
            'occurred_at' => $occurredAt,
            'reason' => $reason !== null ? mb_substr($reason, 0, 1000) : null,
            'failed_items' => array_values($failedItems),
            'inspection_id' => $inspectionId,
            'actor_id' => $actorId,
            'actor_label' => $actorLabel !== null ? mb_substr($actorLabel, 0, 191) : null,
        ]);
    }
}
