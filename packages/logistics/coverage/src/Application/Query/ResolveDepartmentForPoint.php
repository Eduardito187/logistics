<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Shared\Application\Query\Query;

/**
 * Pregunta: ¿a qué departamento (y municipio/ciudad) pertenece este punto (lat, lng)?
 *
 * Resuelve punto → zona → área padre → departamento. Devuelve null si no se puede determinar.
 */
final readonly class ResolveDepartmentForPoint implements Query
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {}
}
