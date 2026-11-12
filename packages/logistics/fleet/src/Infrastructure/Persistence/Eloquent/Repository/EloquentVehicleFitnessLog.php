<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Contract\VehicleFitnessLog;
use Logistics\Fleet\Domain\ValueObject\VehicleFitnessLogEntry;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleFitnessLogModel;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Bitácora de aptitud en Eloquent: solo inserta (append-only), nunca actualiza ni borra.
 */
final readonly class EloquentVehicleFitnessLog implements VehicleFitnessLog
{
    public function append(VehicleFitnessLogEntry $entry, DateTimeImmutable $occurredAt): void
    {
        VehicleFitnessLogModel::query()->create([
            'id' => Uuid::generate()->value,
            'vehicle_id' => $entry->vehicleId,
            'action' => $entry->action,
            'occurred_at' => $occurredAt,
            'reason' => $entry->reason !== null ? mb_substr($entry->reason, 0, 1000) : null,
            'failed_items' => array_values($entry->failedItems),
            'inspection_id' => $entry->inspectionId,
            'actor_id' => $entry->actorId,
            'actor_label' => $entry->actorLabel !== null ? mb_substr($entry->actorLabel, 0, 191) : null,
        ]);
    }
}
