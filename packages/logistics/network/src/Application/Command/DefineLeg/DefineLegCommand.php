<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Command\DefineLeg;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir un tramo/corredor entre dos nodos. DTO inmutable con datos primitivos: el
 * handler los convierte en los value objects del dominio y aplica las validaciones.
 */
final readonly class DefineLegCommand implements Command
{
    public function __construct(
        public string $fromNodeId,
        public string $toNodeId,
        public string $corridorType,
    ) {}
}
