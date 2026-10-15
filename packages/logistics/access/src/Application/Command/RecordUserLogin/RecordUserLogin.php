<?php

declare(strict_types=1);

namespace Logistics\Access\Application\Command\RecordUserLogin;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar que un usuario inició sesión. El instante lo resuelve el handler vía Clock.
 */
final readonly class RecordUserLogin implements Command
{
    public function __construct(
        public string $userId,
    ) {}
}
