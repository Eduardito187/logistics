<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Service;

use DateTimeImmutable;
use Logistics\Promise\Domain\ValueObject\LeadTime;
use Logistics\Promise\Domain\ValueObject\PromiseOption;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;

/**
 * Núcleo del cálculo de la promesa de entrega. PHP puro, sin dependencias de framework ni de otros
 * contextos: recibe la cobertura (perfil de la zona), el corredor (bool) y la capacidad (cupo por franja)
 * ya resueltos por los puertos, y los combina en las fechas y franjas que se pueden prometer.
 *
 * Se parte en dos pasos para que Application consulte capacidad solo de los días que ya pasaron el filtro
 * de cobertura y corredor (menos consultas), y para que cada regla se pueda probar por separado:
 *
 *  1. serviceableDates(): los días del horizonte en que la zona podría entregar —hay corredor, la zona
 *     atiende ese día de la semana y el día no cae antes del tiempo base (lead) desde "ahora".
 *  2. optionsFor(): dentro de un día servible, las franjas con cupo libre que además caen en alguna
 *     ventana de atención de la zona.
 */
final class DeliveryPromiseCalculator
{
    /**
     * Días del horizonte en que la zona podría prometer una entrega.
     *
     * El tiempo base (lead) se cuenta desde `$now`: la fecha más temprana entregable es el día de
     * `$now + leadMinutes`. El horizonte se recorre desde `$from` (inclusive) por `$horizonDays` días.
     * Sin corredor o sin días de servicio, no hay nada que prometer.
     *
     * `$additionalLeadTime` es el tiempo EXTRA del marketplace (seller): se SUMA al tiempo base de la zona
     * antes de calcular la fecha más temprana, empujando la promesa más adelante. Para retail (OWNED) es
     * `null`/`LeadTime::none()` y el resultado es idéntico al histórico (el camino retail no cambia).
     *
     * @return list<DateTimeImmutable> días a medianoche, en orden ascendente
     */
    public function serviceableDates(
        ZoneServiceProfile $profile,
        bool $corridorServed,
        DateTimeImmutable $from,
        DateTimeImmutable $now,
        int $horizonDays,
        ?LeadTime $additionalLeadTime = null,
    ): array {
        if (!$corridorServed || !$profile->hasServiceDays() || $horizonDays < 1) {
            return [];
        }

        $leadMinutes = $profile->leadMinutes + ($additionalLeadTime !== null ? $additionalLeadTime->minutes : 0);
        $earliest = $now->modify("+{$leadMinutes} minutes")->setTime(0, 0, 0);
        $start = $from->setTime(0, 0, 0);

        $dates = [];
        for ($offset = 0; $offset < $horizonDays; $offset++) {
            $day = $start->modify("+{$offset} days");
            if ($day < $earliest) {
                continue;
            }
            if (!$profile->servesWeekday((int) $day->format('N'))) {
                continue;
            }
            $dates[] = $day;
        }

        return $dates;
    }

    /**
     * Franjas que se pueden prometer en un día servible: las que tienen cupo libre y caen en una ventana
     * de atención de la zona. El cupo entra ya resuelto por el puerto de capacidad.
     *
     * @param  list<SlotCapacity>  $capacity
     * @return list<PromiseOption>
     */
    public function optionsFor(ZoneServiceProfile $profile, DateTimeImmutable $date, array $capacity): array
    {
        $options = [];
        foreach ($capacity as $slot) {
            if (!$slot->hasCapacity()) {
                continue;
            }
            if (!$profile->coversSlot($slot->timeSlot)) {
                continue;
            }
            $options[] = PromiseOption::of($date, $slot->timeSlot, $slot->available);
        }

        return $options;
    }
}
