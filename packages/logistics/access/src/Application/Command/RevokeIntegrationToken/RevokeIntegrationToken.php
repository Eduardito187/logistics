<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RevokeIntegrationToken;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de revocar un token de integración para que deje de autenticar peticiones.
 */
final readonly class RevokeIntegrationToken implements Command
{
    public function __construct(
        public string $tokenId,
    ) {}
}
