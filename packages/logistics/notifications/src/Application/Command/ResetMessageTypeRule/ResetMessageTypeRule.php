<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\ResetMessageTypeRule;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para quitar el override de cascada de un tipo de mensaje: ese tipo vuelve a la cascada por
 * defecto del ADR 006. Publica una versión nueva de la configuración (historial preservado).
 */
final readonly class ResetMessageTypeRule implements Command
{
    public function __construct(
        public string $messageType,
        public ?string $createdBy = null,
    ) {}
}
