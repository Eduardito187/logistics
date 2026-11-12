<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Contract;

/**
 * Puerto de lectura de la placa legible de los vehículos (dato maestro del contexto Flota), en LOTE para
 * no caer en N+1 al proyectar listados. fleetops no depende del package fleet: la implementación lee el
 * dato por valor en Infrastructure.
 */
interface VehiclePlateDirectory
{
    /**
     * Placa por id de vehículo. Los ids sin vehículo registrado no aparecen en el mapa.
     *
     * @param  list<string>          $vehicleIds
     * @return array<string, string>
     */
    public function platesFor(array $vehicleIds): array;
}
