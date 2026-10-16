<?php

declare(strict_types=1);

namespace Logistics\Inbound\Application\Query\GetReceivingDockCalendar;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el calendario de andén de recepción: por día (desde `fromDate`, `days` días) y por franja, cuántas
 * citas hay agendadas, opcionalmente de un almacén.
 */
final readonly class GetReceivingDockCalendar implements Query
{
    public function __construct(
        public string $fromDate,
        public int $days = 7,
        public ?string $warehouseId = null,
    ) {}
}
