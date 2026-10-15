<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use DateTimeImmutable;
use Logistics\Promise\Domain\ValueObject\SlotCapacity;

/**
 * Puerto hacia el contexto de capacidad para el calendario de despacho por MUNICIPIO: dado un municipio
 * y una fecha, devuelve el cupo libre de cada franja (etiqueta + entregas disponibles) de ESE municipio.
 *
 * Es el análogo de CapacityCalendar pero sobre el eje municipio —el que usa el calendario de despacho del
 * cliente—, no sobre la zona. Solo devuelve franjas de un día DESPACHABLE (ni bloqueado por feriado/otro
 * motivo ni fuera del horizonte activo de cupos del municipio): esa regla la aplica Infrastructure contra
 * capacity. El cruce es por el bus y por primitivos; el dominio no importa `logistics/capacity`.
 */
interface MunicipalityDispatchCalendar
{
    /**
     * Cupo libre por franja del municipio en la fecha dada. Lista vacía si el día no es despachable o no
     * hay cupo con disponibilidad.
     *
     * @return list<SlotCapacity>
     */
    public function availableSlots(string $municipalityId, DateTimeImmutable $date): array;
}
