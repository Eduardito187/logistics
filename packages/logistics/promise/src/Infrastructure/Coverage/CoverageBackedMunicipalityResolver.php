<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Coverage;

use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Promise\Domain\Contract\MunicipalityResolver;
use Throwable;

/**
 * Implementación del puerto de resolución de municipio apoyada en el contexto de cobertura.
 *
 * Traduce una coordenada (lat/lng) al municipio que la contiene usando el MunicipalityLocator de
 * `logistics/coverage` (PostGIS, punto → zona → … → municipio). El cruce es de solo lectura y por el
 * puerto de dominio de coverage; promise no agrega dependencia composer a coverage (patrón del monorepo):
 * resuelve su localizador desde el contenedor. El día que coverage se extraiga a un microservicio, este
 * adaptador pasa a llamar su API sin tocar el dominio de promise.
 *
 * Tolerante: una coordenada fuera de rango (o cualquier fallo del localizador) se traduce a "municipio
 * desconocido" (null), nunca a un 500: para el canal es, sencillamente, una dirección sin cobertura.
 */
final readonly class CoverageBackedMunicipalityResolver implements MunicipalityResolver
{
    public function __construct(private MunicipalityLocator $locator) {}

    public function resolve(float $latitude, float $longitude): ?string
    {
        try {
            return $this->locator->locate(GeoPoint::of($latitude, $longitude))?->toString();
        } catch (Throwable) {
            return null;
        }
    }
}
