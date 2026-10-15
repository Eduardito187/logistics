<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetActiveHorizon;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el horizonte activo de cupos de un alcance (municipio, o global con municipio null). Devuelve
 * null si nunca se activó ese alcance.
 */
final readonly class GetActiveHorizonQuery implements Query
{
    public function __construct(public ?string $municipalityId = null) {}
}
