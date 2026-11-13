<?php

declare(strict_types=1);

namespace Logistics\Planning\Tests\Feature;

use Logistics\Planning\Domain\Contract\RouteGeometryProvider;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RouteGeometry;

/**
 * Proveedor falso: polilínea = los puntos recibidos ([lng, lat]); cuenta las llamadas y puede fallar.
 */
final class FakeRouteGeometryProvider implements RouteGeometryProvider
{
    public int $calls = 0;

    /** @var list<GeoPoint> */
    public array $lastPoints = [];

    public bool $throws = false;

    public function geometryFor(array $orderedPoints): ?RouteGeometry
    {
        $this->calls++;
        if ($this->throws) {
            throw new SimulatedRouterFailure('ruteador caído');
        }
        $this->lastPoints = array_values($orderedPoints);
        if (count($orderedPoints) < 2) {
            return null;
        }

        return RouteGeometry::of(
            array_map(static fn (GeoPoint $p): array => [$p->longitude, $p->latitude], array_values($orderedPoints)),
            1000.0,
            120.0,
        );
    }
}
