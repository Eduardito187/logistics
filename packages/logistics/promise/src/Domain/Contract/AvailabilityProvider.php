<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use DateTimeImmutable;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Puerto hacia los contextos de capacidad y cobertura: dada una zona y una fecha, devuelve las franjas
 * que todavía tienen cupo disponible para prometer.
 *
 * El dominio define QUÉ necesita (la lista de franjas disponibles); CÓMO se calcula —consultando
 * `logistics/capacity` y `logistics/coverage`— es decisión de Infrastructure. Así este contexto no se
 * acopla a esos packages: los conoce solo por este contrato y por primitivos (zoneId como string).
 */
interface AvailabilityProvider
{
    /**
     * Franjas con cupo disponible en la zona para la fecha dada. Lista vacía si no hay disponibilidad.
     *
     * @return list<DeliverySlot>
     */
    public function availableSlots(string $zoneId, DateTimeImmutable $date): array;
}
