<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Command\ScheduleCarrierTrip;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de la oficina de programar un viaje para un transportista (corredor + fecha + carga inicial).
 *
 * `tripId` es opcional: quien despacha puede fijarlo (UUID v7) para conocer el id sin releer; si no, lo
 * genera el repositorio.
 */
final readonly class ScheduleCarrierTrip implements Command
{
    /**
     * @param list<string> $shipmentIds
     */
    public function __construct(
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $scheduledAt,
        public ?string $reference = null,
        public array $shipmentIds = [],
        public ?string $tripId = null,
    ) {}
}
