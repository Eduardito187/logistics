<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Contract;

use Logistics\Shipments\Domain\Model\ShipmentItinerary;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLegId;

/**
 * Puerta de persistencia del agregado {@see ShipmentItinerary} (los tramos de un envío). El dominio la
 * define; la implementación Eloquent vive en Infrastructure. Reconstruye el itinerario completo (con todos
 * sus tramos ordenados), no filas sueltas.
 */
interface ShipmentLegRepository
{
    /**
     * Reserva una identidad nueva de tramo. El id lo genera el dominio (UUID v7), no la base.
     */
    public function nextLegIdentity(): ShipmentLegId;

    /**
     * Persiste el itinerario: sincroniza sus tramos por id (upsert de los presentes + borrado de los que
     * ya no están), de modo que un tramo existente conserve su fila —y su id— a través de cualquier guardado.
     */
    public function save(ShipmentItinerary $itinerary): void;

    /**
     * Carga el itinerario de un envío. Devuelve un itinerario VACÍO (sin tramos) si el envío todavía no
     * tiene ninguno, nunca null: un envío siempre tiene un itinerario, aunque esté por poblarse.
     */
    public function findByShipmentId(ShipmentId $shipmentId): ShipmentItinerary;

    /**
     * ¿El envío ya tiene al menos un tramo? Clave de idempotencia del backfill (no recrear tramos de un
     * envío que ya los tiene), sin materializar el itinerario completo.
     */
    public function hasLegs(ShipmentId $shipmentId): bool;
}
