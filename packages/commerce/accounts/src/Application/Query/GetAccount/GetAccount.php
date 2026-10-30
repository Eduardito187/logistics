<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\GetAccount;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta una cuenta por su id.
 */
final readonly class GetAccount implements Query
{
    public function __construct(
        public string $accountId,
    ) {}
}
