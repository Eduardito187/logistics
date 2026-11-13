<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Contract;

/**
 * Puerto de lectura para enriquecer proyecciones con valores legibles de la flota: la placa del
 * vehículo y el nombre del chofer. Ambos se resuelven EN LOTE (sin N+1) a partir de sus ids.
 *
 * Vive como contrato para que los QueryHandlers (Application) no dependan de Eloquent. La
 * implementación consulta `fleet_vehicles`/`fleet_drivers` (mismo contexto).
 */
interface FleetLabelReader
{
    /**
     * Placas por id de vehículo. Solo incluye los ids que existen en la flota.
     *
     * @param  list<string>          $vehicleIds
     * @return array<string, string>
     */
    public function platesByVehicleId(array $vehicleIds): array;

    /**
     * Nombres por id de chofer. Solo incluye los ids que existen en la flota.
     *
     * @param  list<string>          $driverIds
     * @return array<string, string>
     */
    public function namesByDriverId(array $driverIds): array;
}
