<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetActivationOptions;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el catálogo con el que se arma una activación de cupos por rango: franjas ofrecidas, unidades
 * válidas, días de la semana y las ventanas por defecto (franja + unidad + cantidad) de las reglas
 * vigentes. `at` es una fecha ISO 8601 opcional (null = ahora); `municipalityId` opcional pide las reglas
 * de ese municipio (cae a las de la red si no tiene propias).
 */
final readonly class GetActivationOptionsQuery implements Query
{
    public function __construct(
        public ?string $at = null,
        public ?string $municipalityId = null,
    ) {}
}
