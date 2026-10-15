<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use DateTimeImmutable;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;

/**
 * Puerto hacia el contexto de capacidad para el cálculo de promesa: dado una zona y una fecha, devuelve
 * el cupo libre de cada franja (etiqueta + entregas disponibles).
 *
 * A diferencia de AvailabilityProvider —que solo dice QUÉ franjas tienen cupo, para cotizar una franja
 * puntual—, este puerto expone el cupo restante por franja, que es lo que el cálculo de promesa necesita
 * para ofrecer y ordenar fechas. El cruce con `logistics/capacity` es por el bus y por primitivos; el
 * dominio no importa ese package.
 */
interface CapacityCalendar
{
    /**
     * Cupo libre por franja en la zona para la fecha dada. Lista vacía si no hay cupo configurado.
     *
     * @return list<SlotCapacity>
     */
    public function availableSlots(string $zoneId, DateTimeImmutable $date): array;
}
