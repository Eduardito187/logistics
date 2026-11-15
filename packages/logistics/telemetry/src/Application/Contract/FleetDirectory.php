<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Contract;

use DateTimeImmutable;

/**
 * Puerto de lectura CROSS-CONTEXT (fleet / execution) para enriquecer las lecturas de telemetría con valores
 * legibles: placa del vehículo y nombre del chofer. Cada lectura EN LOTE (una query por concepto, sin N+1) y
 * tolerante: lo que no se resuelve sale null, nunca rompe la lectura.
 */
interface FleetDirectory
{
    /**
     * Placa por id de vehículo.
     *
     * @param  list<string>               $vehicleIds
     * @return array<string, string|null>
     */
    public function plates(array $vehicleIds): array;

    /**
     * Nombre por id de chofer.
     *
     * @param  list<string>               $driverIds
     * @return array<string, string|null>
     */
    public function driverNames(array $driverIds): array;

    /**
     * Sede de cada vehículo: su nodo base con nombre y departamento (la "ciudad" del camión). Un vehículo
     * sin nodo base conocido no aparece en el mapa.
     *
     * @param  list<string>                                                                           $vehicleIds
     * @return array<string, array{node_id: string, node_name: string|null, department: string|null}>
     */
    public function homeBases(array $vehicleIds): array;

    /**
     * Chofer asignado a la ruta del día de cada vehículo (respaldo cuando la lectura GPS no trae chofer).
     *
     * @param  list<string>               $vehicleIds
     * @return array<string, string|null>
     */
    public function assignedDriverIds(array $vehicleIds, DateTimeImmutable $day): array;
}
