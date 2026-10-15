<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Coverage\Application\ReadModel\LocatedAreaView;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve punto → departamento apoyándose en el localizador PostGIS. Devuelve null si el punto no
 * cae en ninguna zona o no se puede subir hasta un departamento (ubicación desconocida).
 *
 * @implements QueryHandler<ResolveDepartmentForPoint, LocatedAreaView|null>
 */
final readonly class ResolveDepartmentForPointHandler implements QueryHandler
{
    public function __construct(private DepartmentLocator $locator) {}

    public function handle(Query $query): ?LocatedAreaView
    {
        assert($query instanceof ResolveDepartmentForPoint);

        $location = $this->locator->locate(GeoPoint::of($query->lat, $query->lng));

        return $location === null ? null : LocatedAreaView::fromLocation($location);
    }
}
