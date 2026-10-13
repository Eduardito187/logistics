<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Puerto hacia el contexto de capacidad para tomar y soltar cupo de una franja.
 *
 * El dominio define QUÉ necesita (reservar un cupo, liberarlo); CÓMO se descuenta el cupo real lo
 * resuelve `logistics/capacity` detrás de este contrato. Este contexto no importa ese package.
 */
interface SlotReserver
{
    /**
     * Intenta reservar un cupo en la franja de la zona. Devuelve true si quedó reservado, false si ya
     * no había cupo. Idempotente respecto de reservas ya tomadas por la misma promesa.
     */
    public function reserve(string $zoneId, DeliverySlot $slot): bool;

    /**
     * Libera el cupo previamente reservado en la franja de la zona. No falla si no había reserva.
     */
    public function release(string $zoneId, DeliverySlot $slot): void;
}
