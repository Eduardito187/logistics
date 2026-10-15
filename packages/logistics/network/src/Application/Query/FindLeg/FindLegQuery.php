<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\FindLeg;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca un tramo por su UUID. Devuelve null si no existe.
 */
final readonly class FindLegQuery implements Query
{
    public function __construct(public string $legId) {}
}
