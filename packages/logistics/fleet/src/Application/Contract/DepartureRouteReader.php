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
 * @phpstan-type OpenDriverRoute array{route_id: string, vehicle_id: string, status: string, driver_id: string, route_date: string}
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

    /**
     * Entrega EN CURSO (o pausada) de ESE chofer, resuelta por su `driver_id` DIRECTO en la ruta —NO por el
     * camión de una tripulación—, la más reciente sin importar el día. Es la asignación vigente que no se le
     * quita al cruzar la medianoche (entrega larga, a otra ciudad o país). Trae la fecha de la ruta para
     * reconstruir la tripulación de ese día. Devuelve null si el chofer no tiene ninguna ruta abierta (o si
     * Ejecución no está disponible).
     *
     * @return OpenDriverRoute|null
     */
    public function openForDriver(string $driverId): ?array;
}
