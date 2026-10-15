<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Contract;

use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/**
 * Puerto hacia el contexto de capacidad para tomar y soltar cupo de una franja por MUNICIPIO.
 *
 * Es el análogo de SlotReserver pero sobre el eje municipio —el calendario de despacho del cliente—, no
 * sobre la zona. Reservar consume UNA entrega del cupo del municipio para esa fecha y franja; liberar lo
 * devuelve. El descuento real lo hace `logistics/capacity` con lock pesimista detrás de este contrato;
 * este contexto no importa ese package. El cruce es por el bus y por primitivos.
 */
interface MunicipalitySlotReserver
{
    /**
     * Intenta reservar un cupo en la franja del municipio. Devuelve true si quedó reservado, false si ya
     * no había cupo (agotado o inexistente para ese municipio/fecha/franja).
     */
    public function reserve(string $municipalityId, DeliverySlot $slot): bool;

    /**
     * Libera el cupo previamente reservado en la franja del municipio. No falla si no había reserva.
     */
    public function release(string $municipalityId, DeliverySlot $slot): void;
}
