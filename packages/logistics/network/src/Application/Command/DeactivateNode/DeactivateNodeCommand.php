<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\DeactivateNode;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de dar de baja un nodo, identificado por su UUID.
 */
final readonly class DeactivateNodeCommand implements Command
{
    public function __construct(public string $nodeId) {}
}
