<?php

declare(strict_types=1);

namespace Logistics\Notifications\Application\Command\SetMessageTypeRule;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para configurar la cascada de un tipo de mensaje (P1-NOT-01): qué canales, en qué orden y
 * con qué condición de escalado. Reemplaza el default del ADR 006 para ese tipo sin tocar el dominio.
 */
final readonly class SetMessageTypeRule implements Command
{
    /**
     * @param list<string> $channels Canales en orden de cascada (valores de Channel).
     */
    public function __construct(
        public string $messageType,
        public array $channels,
        public string $escalateWhen,
        public ?string $createdBy = null,
    ) {}
}
