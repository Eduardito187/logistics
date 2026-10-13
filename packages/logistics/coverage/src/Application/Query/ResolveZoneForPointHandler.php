<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve punto→zona apoyándose en PostGIS (ST_Contains). Devuelve null si el punto no cae en
 * ninguna zona.
 *
 * @implements QueryHandler<ResolveZoneForPoint, ZoneView|null>
 */
final readonly class ResolveZoneForPointHandler implements QueryHandler
{
    public function __construct(private ZoneRepository $zones) {}

    public function handle(Query $query): ?ZoneView
    {
        assert($query instanceof ResolveZoneForPoint);

        $zone = $this->zones->findZoneContaining(GeoPoint::of($query->lat, $query->lng));

        return $zone === null ? null : ZoneView::fromZone($zone);
    }
}
