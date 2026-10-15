<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetCapacityRuleset;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide las reglas de cupo vigentes a una fecha. `at` es una fecha ISO 8601 opcional; si viene null,
 * se consultan las vigentes "ahora" (lo resuelve el handler con el Clock). `municipalityId` opcional
 * pide las reglas del calendario de 7 días de ese municipio (cae a las de la red si no tiene propias).
 */
final readonly class GetCapacityRulesetQuery implements Query
{
    public function __construct(
        public ?string $at = null,
        public ?string $municipalityId = null,
    ) {}
}
