<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Command\ActivateCapacityWindow;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de ACTIVAR cupos por rango de fechas y por municipio: "activá cupos de estas ventanas, estos
 * días de la semana, desde X hasta Y, para estos municipios". Es la operación central del calendario de
 * 7 días con activación hasta una fecha (p. ej. hasta noviembre).
 *
 * - `municipalityIds`: uno o varios municipios (UUID de coverage). "Todos" se resuelve antes, en la capa
 *   que dispara la activación, expandiendo la lista de municipios; capacity no enumera coverage.
 * - `weekdays`: días de la semana activos (1 = lunes … 7 = domingo); permite cubrir los 7 días.
 * - `windows`: lista de ventanas primitivas {time_slot, unit, quantity}: la franja, la unidad y el cupo.
 * - La generación de los buckets es ASÍNCRONA (job encolado, troceado por lotes); el comando solo valida,
 *   registra/avanza el horizonte activo por municipio y encola el trabajo.
 *
 * @phpstan-type RawWindow array{time_slot: string, unit: string, quantity: float|int}
 */
final readonly class ActivateCapacityWindowCommand implements Command
{
    /**
     * @param list<string>                                                      $municipalityIds
     * @param list<int>                                                         $weekdays
     * @param list<array{time_slot: string, unit: string, quantity: float|int}> $windows
     */
    public function __construct(
        public array $municipalityIds,
        public string $fromDate,
        public string $toDate,
        public array $weekdays,
        public array $windows,
        public ?string $activatedBy = null,
    ) {}
}
