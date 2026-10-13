<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\GetPromise;

use Logistics\Shared\Application\Query\Query;

/**
 * Busca una promesa por su id. Devuelve un PromiseView o null si no existe.
 */
final readonly class GetPromise implements Query
{
    public function __construct(
        public string $promiseId,
    ) {}
}
