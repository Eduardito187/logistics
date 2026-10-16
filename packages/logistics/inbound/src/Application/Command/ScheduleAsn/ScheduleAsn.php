<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Command\ScheduleAsn;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de agendar la cita de un ASN: día y franja de recepción en el andén. Reserva el cupo de andén.
 */
final readonly class ScheduleAsn implements Command
{
    public function __construct(
        public string $asnId,
        public string $appointmentDate,
        public string $startTime,
        public string $endTime,
    ) {}
}
