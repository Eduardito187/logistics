<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\UnblockDispatchDay;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de desbloquear un día de despacho previamente bloqueado. `municipalityId` null apunta al
 * bloqueo global; poblado, al del municipio. Identifica el bloqueo por su clave natural (fecha+municipio).
 */
final readonly class UnblockDispatchDayCommand implements Command
{
    public function __construct(
        public string $date,
        public ?string $municipalityId,
    ) {}
}
