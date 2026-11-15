<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

use Logistics\Planning\Application\ReadModel\TripVehicleView;

/**
 * Puerto de LECTURA del camión y su base para el armado de viajes y para mostrar de dónde sale un plan.
 * El adaptador de Infrastructure lee Flota y Red en solo lectura y en lote.
 */
interface TripVehicleReader
{
    /**
     * Camiones por id. Los vehículos inexistentes (o los ids sin forma de UUID) no aparecen en el mapa.
     *
     * @param  list<string>                   $vehicleIds
     * @return array<string, TripVehicleView>
     */
    public function vehiclesFor(array $vehicleIds): array;
}
