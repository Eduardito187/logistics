<?php

declare(strict_types=1);

namespace Commerce\Accounts\Application\Query\GetAccountByUser;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta la cuenta comercial asociada a un usuario de `access` (por UUID).
 */
final readonly class GetAccountByUser implements Query
{
    public function __construct(
        public string $userId,
    ) {}
}
