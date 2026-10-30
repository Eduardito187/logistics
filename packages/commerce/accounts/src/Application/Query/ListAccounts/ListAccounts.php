<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\ListAccounts;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista cuentas, opcionalmente filtrando por tipo (person|company|seller_person).
 */
final readonly class ListAccounts implements Query
{
    public function __construct(
        public ?string $type = null,
    ) {}
}
