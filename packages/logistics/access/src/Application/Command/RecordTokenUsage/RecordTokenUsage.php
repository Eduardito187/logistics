<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RecordTokenUsage;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dejar constancia de que un token de integración se usó para autenticar una petición.
 */
final readonly class RecordTokenUsage implements Command
{
    public function __construct(
        public string $tokenId,
    ) {}
}
