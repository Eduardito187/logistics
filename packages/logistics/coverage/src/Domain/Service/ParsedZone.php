<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Service;

use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Resultado de parsear un Feature del GeoJSON: el material con el que el caso de uso arma una Zone.
 *
 * No trae ZoneId: el id lo asigna el repositorio al importar (nextIdentity), no el mapa de entrada.
 */
final readonly class ParsedZone
{
    public function __construct(
        public string $name,
        public Polygon $polygon,
        public ZoneRules $rules,
    ) {}
}
