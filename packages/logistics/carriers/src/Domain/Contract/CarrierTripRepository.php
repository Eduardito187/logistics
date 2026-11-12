<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Contract;

use Logistics\Carriers\Domain\Model\CarrierTrip;
use Logistics\Carriers\Domain\ValueObject\CarrierTripId;

/**
 * Puerto de persistencia de viajes de transportista (cabecera + carga). La implementación vive en
 * Infrastructure.
 */
interface CarrierTripRepository
{
    public function nextIdentity(): CarrierTripId;

    /**
     * Persiste cabecera y carga (sincroniza los envíos: agrega los nuevos y quita los removidos).
     */
    public function save(CarrierTrip $trip): void;

    public function findById(CarrierTripId $id): ?CarrierTrip;

    /**
     * Igual que findById pero bloquea la fila (SELECT ... FOR UPDATE) hasta el fin de la transacción del
     * comando: dos inicios/cierres simultáneos del mismo viaje se serializan y el segundo ve el estado
     * ya cambiado (idempotencia real ante reintentos concurrentes).
     */
    public function findByIdForUpdate(CarrierTripId $id): ?CarrierTrip;

    /**
     * Envíos (de los dados) que ya están en OTRO viaje vigente (programado o en ruta).
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string> shipment_id => trip_id que lo retiene
     */
    public function shipmentsHeldByOtherActiveTrips(array $shipmentIds, ?CarrierTripId $except = null): array;
}
