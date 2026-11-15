<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Contract;

/**
 * Puerta de lectura de las SALIDAS (rutas de reparto) de un día, para saber cuál es la salida vigente de
 * un chofer. Un camión puede salir varias veces el mismo día y un chofer puede cambiar de camión entre
 * salidas: cada salida es una ruta con su propio id.
 *
 * Flota no se acopla al dominio de Ejecución: pregunta en primitivos y recibe primitivos.
 *
 * @phpstan-type DepartureRoute array{route_id: string, vehicle_id: string, status: string, driver_id: string|null}
 */
interface DepartureRouteReader
{
    /**
     * Rutas de esos vehículos en la fecha (YYYY-MM-DD), de la más reciente a la más vieja. Lista vacía si
     * no hay ninguna (o si Ejecución no está disponible).
     *
     * @param  list<string>         $vehicleIds
     * @return list<DepartureRoute>
     */
    public function onDate(string $date, array $vehicleIds): array;
}
