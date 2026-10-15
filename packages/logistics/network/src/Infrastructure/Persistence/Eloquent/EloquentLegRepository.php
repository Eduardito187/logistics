<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Logistics\Network\Domain\Contract\LegRepository;
use Logistics\Network\Domain\Leg;
use Logistics\Network\Domain\ValueObject\CorridorType;
use Logistics\Network\Domain\ValueObject\LegId;
use Logistics\Network\Domain\ValueObject\NodeId;

/**
 * Implementación Eloquent del repositorio de tramos/corredores.
 *
 * Mapea la fila network_legs hacia y desde el agregado Leg. El id es el UUID v7 del tramo; el borrado
 * es físico (el agregado registra el evento LegRemoved antes de que se borre la fila).
 */
final class EloquentLegRepository implements LegRepository
{
    public function nextIdentity(): LegId
    {
        return LegId::generate();
    }

    public function save(Leg $leg): void
    {
        $id = $leg->id()->toString();

        $record = LegRecord::query()->find($id) ?? new LegRecord;
        $record->id = $id;
        $record->from_node_id = $leg->from()->toString();
        $record->to_node_id = $leg->to()->toString();
        $record->corridor_type = $leg->corridor()->value;
        $record->save();
    }

    public function remove(Leg $leg): void
    {
        LegRecord::query()->whereKey($leg->id()->toString())->delete();
    }

    public function findById(LegId $id): ?Leg
    {
        $record = LegRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByEndpoints(NodeId $from, NodeId $to, CorridorType $corridor): ?Leg
    {
        $record = LegRecord::query()
            ->where('from_node_id', $from->toString())
            ->where('to_node_id', $to->toString())
            ->where('corridor_type', $corridor->value)
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<Leg>
     */
    public function all(): array
    {
        return LegRecord::query()
            ->orderBy('corridor_type')
            ->get()
            ->map(fn (LegRecord $record): Leg => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(LegRecord $record): Leg
    {
        return Leg::fromState(
            LegId::fromString($record->id),
            NodeId::fromString($record->from_node_id),
            NodeId::fromString($record->to_node_id),
            CorridorType::from($record->corridor_type),
        );
    }
}
