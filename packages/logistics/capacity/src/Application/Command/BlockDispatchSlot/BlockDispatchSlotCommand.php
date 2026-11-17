<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\BlockDispatchSlot;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de bloquear UNA franja horaria de despacho (sin bloquear el día entero). `timeSlot` es
 * 'HH:MM-HH:MM'. `municipalityId` null = bloqueo global (toda la red); poblado = solo ese municipio.
 * `reason` es obligatorio (auditoría). `blockedBy` identifica a quién lo hizo.
 */
final readonly class BlockDispatchSlotCommand implements Command
{
    public function __construct(
        public string $date,
        public string $timeSlot,
        public ?string $municipalityId,
        public string $reason,
        public ?string $blockedBy = null,
    ) {}
}
