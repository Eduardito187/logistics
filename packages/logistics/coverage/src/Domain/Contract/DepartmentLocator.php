<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\ValueObject\AreaLocation;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;

/**
 * Puerto de solo lectura que resuelve a qué departamento (y municipio/ciudad) pertenece una
 * coordenada, subiendo por la jerarquía administrativa desde la zona que la contiene:
 * punto → zona → área padre (ciudad) → … → departamento.
 *
 * Es tolerante por contrato: si el punto no cae en ninguna zona o no se puede llegar a un
 * departamento, devuelve null (ubicación desconocida) en vez de lanzar. La implementación vive en
 * Infrastructure y se apoya en PostGIS. Otros contextos (p. ej. planificación) dependen de este
 * puerto para aplicar la regla geográfica de rutas sin conocer el detalle de persistencia.
 */
interface DepartmentLocator
{
    public function locate(GeoPoint $point): ?AreaLocation;
}
