<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\UnblockDispatchSlot;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de quitar el bloqueo de UNA franja horaria de despacho. `timeSlot` es 'HH:MM-HH:MM'.
 * `municipalityId` null = quita el bloqueo global; poblado = el del municipio. Idempotente: si no existe
 * un bloqueo para esa clave, el handler no hace nada.
 */
final readonly class UnblockDispatchSlotCommand implements Command
{
    public function __construct(
        public string $date,
        public string $timeSlot,
        public ?string $municipalityId,
    ) {}
}
