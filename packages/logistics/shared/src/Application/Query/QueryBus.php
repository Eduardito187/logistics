<?php

declare(strict_types=1);

namespace Logistics\Shared\Application\Query;

/**
 * Despacha una consulta a su handler y devuelve el resultado.
 */
interface QueryBus
{
    public function ask(Query $query): mixed;
}
