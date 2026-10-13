<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Query;

use Logistics\Shared\Application\Query\Query;

/**
 * Pregunta: ¿en qué zona de cobertura cae este punto (lat, lng)?
 */
final readonly class ResolveZoneForPoint implements Query
{
    public function __construct(
        public float $lat,
        public float $lng,
    ) {}
}
