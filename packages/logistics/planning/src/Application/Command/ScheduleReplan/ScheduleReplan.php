<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Command\ScheduleReplan;

use Logistics\Shared\Application\Command\Command;

/**
 * Encola uno o varios envíos para REPLANIFICAR en una fecha (p. ej. porque el camión que los llevaba
 * sufrió un incidente MAYOR y quedaron sin entregar). La planificación de ese día los rearma en planes.
 *
 * `planDate` es 'Y-m-d'. `reason` describe el origen (p. ej. 'vehicle_incident'). Idempotente por
 * envío+fecha en el repositorio.
 */
final readonly class ScheduleReplan implements Command
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        public array $shipmentIds,
        public string $planDate,
        public string $reason,
        public ?string $sourceRouteId = null,
    ) {}
}
