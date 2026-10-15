<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\GenerateDispatchCalendar;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de materializar el calendario de cupos del horizonte a partir de las reglas vigentes.
 *
 * `from` es la fecha de inicio 'Y-m-d' (null = hoy); `horizonDays` reemplaza el horizonte de las
 * reglas (null = el de las reglas); `at` es la fecha ISO 8601 de la versión de reglas a usar
 * (null = ahora). El handler no se despacha por el bus: lo invoca directo la consola/el admin, y
 * despacha un DefineCapacity por cada cupo faltante (cada uno en su propia transacción + outbox).
 */
final readonly class GenerateDispatchCalendar implements Command
{
    public function __construct(
        public ?string $from = null,
        public ?int $horizonDays = null,
        public ?string $at = null,
    ) {}
}
