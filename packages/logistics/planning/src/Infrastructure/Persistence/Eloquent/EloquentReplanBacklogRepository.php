<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del backlog de replanificación. Idempotente por (shipment_id, plan_date):
 * encolar dos veces el mismo envío para el mismo día actualiza el motivo/origen sin duplicar la fila.
 */
final class EloquentReplanBacklogRepository implements ReplanBacklogRepository
{
    public function enqueue(string $shipmentId, PlanDate $date, string $reason, ?string $sourceRouteId): void
    {
        // El id (PK) solo se fija al crear; en una reafirmación se conserva y se actualizan motivo/origen.
        $record = ShipmentReplanRecord::query()->firstOrNew(
            ['shipment_id' => $shipmentId, 'plan_date' => $date->toString()],
        );

        if (!$record->exists) {
            $record->id = Uuid::generate()->value;
        }

        $record->reason = $reason;
        $record->source_route_id = $sourceRouteId;
        $record->save();
    }
}
