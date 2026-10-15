<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Contract;

use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\MunicipalityId;

/**
 * Puerto de solo lectura que resuelve a qué municipio pertenece una coordenada.
 *
 * Análogo al DepartmentLocator, pero acotado al nivel Municipio: es el eje por el que el contexto de
 * capacidad gestiona los cupos de despacho. Resuelve el punto subiendo por la jerarquía administrativa
 * desde la zona de cobertura que lo contiene (punto → zona → área padre → … → municipio).
 *
 * Es tolerante por contrato: si el punto no cae en ninguna zona o no se puede llegar a un municipio,
 * devuelve null (ubicación desconocida) en vez de lanzar. La implementación vive en Infrastructure y se
 * apoya en PostGIS.
 */
interface MunicipalityLocator
{
    public function locate(GeoPoint $point): ?MunicipalityId;
}
