<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Query\FindNode;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca un nodo por su UUID. Devuelve null si no existe.
 */
final readonly class FindNodeQuery implements Query
{
    public function __construct(public string $nodeId) {}
}
