<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Execution\Domain\Contract\NodeReceptionRepository;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Implementación Eloquent del repositorio de recepciones en nodo.
 *
 * save() es un upsert transaccional: reescribe la fila de la recepción (estado, conciliación) e inserta
 * los bultos recibidos nuevos. Los bultos son append-only: se insertan con firstOrCreate por clave de
 * idempotencia (inmutables, nunca se reescriben) y el índice único en idempotency_key es el respaldo
 * final de la idempotencia.
 */
final class EloquentNodeReceptionRepository implements NodeReceptionRepository
{
    public function __construct(
        private readonly NodeReceptionMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): NodeReceptionId
    {
        return NodeReceptionId::generate();
    }

    public function save(NodeReception $reception): void
    {
        $this->connections->connection()->transaction(function () use ($reception): void {
            NodeReceptionRecord::query()->updateOrCreate(
                ['id' => $reception->id()->toString()],
                $this->mapper->toReceptionRow($reception),
            );

            foreach ($reception->receivedUnits() as $unit) {
                /** @var array<string, mixed> $values */
                $values = array_merge(
                    ['id' => Uuid::generate()->value],
                    $this->mapper->toUnitRow($reception, $unit),
                );

                NodeReceptionUnitRecord::query()->firstOrCreate(
                    ['idempotency_key' => $unit->idempotencyKey],
                    $values,
                );
            }

            // Ubicación en estante VIGENTE: una fila por (recepción, producto). El agregado ya decidió si
            // la ubicación cambia (idempotencia y orden offline); aquí solo se refleja su estado.
            foreach ($reception->shelfLocations() as $location) {
                $record = NodeReceptionUnitLocationRecord::query()->firstOrNew([
                    'node_reception_id' => $reception->id()->toString(),
                    'unit_id' => $location->unitId,
                ]);
                if (!$record->exists) {
                    $record->id = Uuid::generate()->value;
                }
                $record->fill($this->mapper->toLocationRow($reception, $location));
                if ($record->isDirty()) {
                    $record->save();
                }
            }
        });
    }

    public function findById(NodeReceptionId $id): ?NodeReception
    {
        $record = NodeReceptionRecord::query()
            ->with(['units', 'locations'])
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipmentAndDestination(string $shipmentId, string $destinationNodeId): ?NodeReception
    {
        $record = NodeReceptionRecord::query()
            ->with(['units', 'locations'])
            ->where('shipment_id', $shipmentId)
            ->where('destination_node_id', $destinationNodeId)
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }
}
