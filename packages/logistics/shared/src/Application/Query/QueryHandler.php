<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Query;

/**
 * Maneja exactamente un tipo de consulta y devuelve su resultado.
 *
 * @template T of Query
 * @template R
 */
interface QueryHandler
{
    /**
     * @param T $query
     * @return R
     */
    public function handle(Query $query): mixed;
}
