<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\BlockDispatchDay;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de bloquear un día de despacho (feriado u otro motivo). `municipalityId` null = bloqueo
 * global (toda la red); poblado = solo ese municipio. `reason` es obligatorio (auditoría). `blockedBy`
 * identifica a quién lo hizo.
 */
final readonly class BlockDispatchDayCommand implements Command
{
    public function __construct(
        public string $date,
        public ?string $municipalityId,
        public string $reason,
        public ?string $blockedBy = null,
    ) {}
}
