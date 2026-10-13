<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Shared\Application\Query\Query;

/**
 * Pregunta: ¿cuál es la regla de cobertura vigente de una zona a una fecha dada?
 *
 * `at` es una fecha ISO 8601 opcional; si viene null, se consulta la vigente a "ahora". Devuelve null
 * si la zona no tiene ninguna versión de regla publicada.
 */
final readonly class GetEffectiveZoneRule implements Query
{
    public function __construct(
        public string $zoneId,
        public ?string $at = null,
    ) {}
}
