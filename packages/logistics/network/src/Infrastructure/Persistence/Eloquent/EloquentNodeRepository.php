<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Illuminate\Support\Facades\DB;
use Logistics\Network\Domain\Contract\NodeRepository;
use Logistics\Network\Domain\Node;
use Logistics\Network\Domain\ValueObject\Department;
use Logistics\Network\Domain\ValueObject\GeoPoint;
use Logistics\Network\Domain\ValueObject\NodeCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Implementación Eloquent del repositorio de nodos.
 *
 * Guarda lat/lng como columnas numéricas (fuente de verdad del dominio) y además mantiene una columna
 * PostGIS `location` (geography Point, SRID 4326) poblada por SQL, para consultas espaciales futuras
 * (vecinos más cercanos, cobertura por radio). La columna geográfica es derivada: siempre se recalcula
 * desde lat/lng al guardar.
 */
final class EloquentNodeRepository implements NodeRepository
{
    public function nextIdentity(): NodeId
    {
        return NodeId::generate();
    }

    public function save(Node $node): void
    {
        $id = $node->id()->toString();
        $latitude = $node->location()->latitude;
        $longitude = $node->location()->longitude;

        $record = NodeRecord::query()->find($id) ?? new NodeRecord;
        $record->id = $id;
        $record->code = $node->code()->toString();
        $record->name = $node->name();
        $record->type = $node->type()->value;
        $record->lat = (string) $latitude;
        $record->lng = (string) $longitude;
        $record->status = $node->status()->value;
        $record->department = $node->department()?->value;
        $record->save();

        // La columna geográfica se puebla por SQL: PostGIS arma el punto (lng, lat) en SRID 4326.
        DB::statement(
            'UPDATE network_nodes SET location = ST_SetSRID(ST_MakePoint(?, ?), 4326) WHERE id = ?',
            [$longitude, $latitude, $id],
        );
    }

    public function findById(NodeId $id): ?Node
    {
        $record = NodeRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByCode(NodeCode $code): ?Node
    {
        $record = NodeRecord::query()->where('code', $code->toString())->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<Node>
     */
    public function all(): array
    {
        return NodeRecord::query()
            ->orderBy('code')
            ->get()
            ->map(fn (NodeRecord $record): Node => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(NodeRecord $record): Node
    {
        return Node::fromState(
            NodeId::fromString($record->id),
            NodeCode::fromString($record->code),
            $record->name,
            NodeType::from($record->type),
            GeoPoint::of((float) $record->lat, (float) $record->lng),
            NodeStatus::from($record->status),
            $record->department === null ? null : Department::from($record->department),
        );
    }
}
