<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\DeactivateFailureReason;

use Logistics\Shared\Application\Command\Command;

/**
 * Desactiva (soft) un motivo del catálogo por su code. Decisión del dueño: DELETE = DESACTIVAR, nunca
 * borrado físico. El motivo deja de ofrecerse al chofer pero las entregas históricas que lo usaron siguen
 * legibles.
 */
final readonly class DeactivateFailureReason implements Command
{
    public function __construct(
        public string $code,
    ) {}
}
