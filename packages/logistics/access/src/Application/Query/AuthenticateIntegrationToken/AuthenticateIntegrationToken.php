<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Query\AuthenticateIntegrationToken;

use Logistics\Shared\Application\Query\Query;

/**
 * Intención de autenticar un token de integración a partir del valor completo "<prefix>.<secret>" que
 * presenta el integrador.
 */
final readonly class AuthenticateIntegrationToken implements Query
{
    public function __construct(
        public string $fullToken,
    ) {}
}
