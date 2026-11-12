<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use Illuminate\Support\Facades\Schema;
use Logistics\Planning\Domain\Contract\ReplanBacklogRepository;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Shared\Domain\ValueObject\Uuid;
use Throwable;

/**
 * Implementación Eloquent del backlog de replanificación. Idempotente por (shipment_id, plan_date):
 * encolar dos veces el mismo envío para el mismo día actualiza el motivo/origen sin duplicar la fila.
 *
 * El consumo se marca en `processed_at` (migración aditiva). Tolerante: si la columna todavía no existe
 * (migración sin aplicar), la lectura de pendientes devuelve todas las filas del día y la marca de consumo
 * no hace nada, de modo que planificar nunca se cae por el esquema.
 */
final class EloquentReplanBacklogRepository implements ReplanBacklogRepository
{
    /** Tope de ids por sentencia UPDATE ... WHERE IN, para no armar consultas gigantes. */
    private const UPDATE_CHUNK = 500;

    /** Memo por instancia de si existe `processed_at` (una sola consulta de esquema). */
    private ?bool $hasProcessedAt = null;

    public function enqueue(string $shipmentId, PlanDate $date, string $reason, ?string $sourceRouteId): void
    {
        // El id (PK) solo se fija al crear; en una reafirmación se conserva y se actualizan motivo/origen.
        $record = ShipmentReplanRecord::query()->firstOrNew(
            ['shipment_id' => $shipmentId, 'plan_date' => $date->toString()],
        );

        if (!$record->exists) {
            $record->id = Uuid::generate()->value;
        } elseif ($this->hasProcessedAt() && $record->processed_at !== null
            && $record->reason === $reason && $record->source_route_id === $sourceRouteId) {
            // Reentrega at-least-once del MISMO hecho sobre una fila ya consumida: no se reabre.
            return;
        }

        $record->reason = $reason;
        $record->source_route_id = $sourceRouteId;
        if ($this->hasProcessedAt()) {
            // Un hecho nuevo (otro motivo u otra ruta) vuelve a dejar el envío pendiente de replanificar.
            $record->processed_at = null;
        }
        $record->save();
    }

    public function pendingShipmentIdsFor(PlanDate $date): array
    {
        try {
            $query = ShipmentReplanRecord::query()->where('plan_date', $date->toString());
            if ($this->hasProcessedAt()) {
                $query->whereNull('processed_at');
            }

            $ids = [];
            foreach ($query->orderBy('shipment_id')->pluck('shipment_id') as $shipmentId) {
                $ids[] = (string) $shipmentId;
            }
        } catch (Throwable) {
            return [];
        }

        return array_values(array_unique($ids));
    }

    public function markProcessed(array $shipmentIds, PlanDate $date, DateTimeImmutable $at): void
    {
        if ($shipmentIds === [] || !$this->hasProcessedAt()) {
            return;
        }

        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => trim($id) !== '')));

        foreach (array_chunk($ids, self::UPDATE_CHUNK) as $chunk) {
            ShipmentReplanRecord::query()
                ->where('plan_date', $date->toString())
                ->whereIn('shipment_id', $chunk)
                ->whereNull('processed_at')
                ->update(['processed_at' => $at->format('Y-m-d H:i:sP')]);
        }
    }

    private function hasProcessedAt(): bool
    {
        if ($this->hasProcessedAt === null) {
            try {
                $this->hasProcessedAt = Schema::hasColumn('planning_shipment_replans', 'processed_at');
            } catch (Throwable) {
                $this->hasProcessedAt = false;
            }
        }

        return $this->hasProcessedAt;
    }
}
