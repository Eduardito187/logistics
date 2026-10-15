<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\RemoveLeg;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de eliminar un tramo/corredor de la red, identificado por su UUID.
 */
final readonly class RemoveLegCommand implements Command
{
    public function __construct(public string $legId) {}
}
