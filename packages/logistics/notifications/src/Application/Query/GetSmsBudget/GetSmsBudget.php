<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Query\GetSmsBudget;

use Logistics\Shared\Application\Query\Query;

/**
 * Obtiene el presupuesto de SMS de un período (mes `YYYY-MM`). Si no se pasa período, usa el actual.
 */
final readonly class GetSmsBudget implements Query
{
    public function __construct(
        public ?string $period = null,
    ) {}
}
