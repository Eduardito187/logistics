<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Network\Infrastructure\Persistence\Eloquent\NodeRecord;
use Logistics\Planning\Domain\Contract\PlanningOriginLocator;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Adaptador que resuelve las coordenadas del nodo CD de origen leyendo el contexto de Red (NodeRecord).
 * Vive en Infrastructure (de solo lectura de otro contexto) y traduce al punto de dominio GeoPoint.
 */
final readonly class NetworkNodeOriginLocator implements PlanningOriginLocator
{
    public function locate(?string $originNodeId): ?GeoPoint
    {
        if ($originNodeId === null || trim($originNodeId) === '') {
            return null;
        }

        $node = NodeRecord::query()->find(trim($originNodeId));
        if ($node === null || !is_numeric($node->lat) || !is_numeric($node->lng)) {
            return null;
        }

        return GeoPoint::of((float) $node->lat, (float) $node->lng);
    }
}
