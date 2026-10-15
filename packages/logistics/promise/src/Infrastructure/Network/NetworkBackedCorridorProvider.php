<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Network;

use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\LegRecord;
use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Promise\Domain\Contract\CorridorAvailabilityProvider;

/**
 * Implementación del puerto de corredor apoyada en el contexto de red.
 *
 * Resuelve el nodo que sirve a la zona (coverage_zones.rules.nodeId) y considera que hay corredor cuando
 * ese nodo está activo en la red (network_nodes.status = 'active') y existe al menos un tramo que lo
 * conecta (network_legs). Sin nodo configurado, con el nodo inactivo o sin ningún tramo que lo toque, no
 * hay corredor operativo hacia la zona y no se puede prometer.
 *
 * Es lectura de solo consulta y por primitivos: promise no agrega dependencia composer ni a coverage ni a
 * network (patrón del monorepo); solo lee sus Records para decidir.
 */
final class NetworkBackedCorridorProvider implements CorridorAvailabilityProvider
{
    /** Estado "activo" de un nodo de la red (NodeStatus::Active). */
    private const string NODE_ACTIVE = 'active';

    public function servesZone(string $zoneId): bool
    {
        $nodeId = $this->servingNodeId($zoneId);
        if ($nodeId === null) {
            return false;
        }

        $nodeActive = NodeRecord::query()
            ->whereKey($nodeId)
            ->where('status', self::NODE_ACTIVE)
            ->exists();
        if (!$nodeActive) {
            return false;
        }

        return LegRecord::query()
            ->where('from_node_id', $nodeId)
            ->orWhere('to_node_id', $nodeId)
            ->exists();
    }

    /**
     * Id del nodo que sirve a la zona, leído de las reglas de cobertura; null si no está configurado.
     */
    private function servingNodeId(string $zoneId): ?string
    {
        $record = ZoneRecord::query()->whereKey($zoneId)->first();
        if ($record === null || $record->active !== true) {
            return null;
        }

        /** @var array<string, mixed> $rules */
        $rules = $record->rules;
        $nodeId = $rules['nodeId'] ?? null;

        return is_string($nodeId) && $nodeId !== '' ? $nodeId : null;
    }
}
