<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\Query\GetDeliveryCalendarDay;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el calendario de entregas de UN día: el bloqueo de día entero y sus franjas horarias con cupo,
 * consumo y bloqueo por franja. Con municipio, las franjas son las de ese municipio; sin municipio, el
 * consolidado GLOBAL (suma por franja y unidad de todos los municipios).
 */
final readonly class GetDeliveryCalendarDayQuery implements Query
{
    public function __construct(
        public string $date,
        public ?string $municipalityId = null,
    ) {}
}
