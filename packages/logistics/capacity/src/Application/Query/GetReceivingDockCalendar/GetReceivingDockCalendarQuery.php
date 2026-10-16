<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetReceivingDockCalendar;

use Logistics\Shared\Application\Query\Query;

/**
 * Lee los cupos de RECEPCIÓN (andenes) de un almacén central en una fecha. Es la lectura que alimenta el
 * calendario de andenes del cockpit de inbound (roadmap marketplace §5, paso 4: GetReceivingDockCalendar).
 */
final readonly class GetReceivingDockCalendarQuery implements Query
{
    public function __construct(
        public string $warehouseId,
        public string $date,
    ) {}
}
