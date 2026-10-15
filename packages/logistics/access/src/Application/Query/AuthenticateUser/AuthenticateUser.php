<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\AuthenticateUser;

use Logistics\Shared\Application\Query\Query;

/**
 * Verifica credenciales de un usuario. Es una consulta: no cambia estado ni registra el login (eso lo
 * hace el comando RecordUserLogin aparte).
 */
final readonly class AuthenticateUser implements Query
{
    public function __construct(
        public string $email,
        public string $plainPassword,
    ) {}
}
