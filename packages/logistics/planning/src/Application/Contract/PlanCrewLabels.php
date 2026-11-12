<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

/**
 * Puerto de LECTURA (read-side) para enriquecer los planes con datos humanos de Flota/Ejecución: la placa
 * del camión y el nombre del chofer del día. Planificación no se acopla al esquema de esos contextos; el
 * adaptador de Infrastructure resuelve cada dato EN LOTE (whereIn), nunca una consulta por plan.
 */
interface PlanCrewLabels
{
    /** Separador de la clave (vehículo, día) del mapa de choferes: "<vehicleId>|<Y-m-d>". */
    public const SLOT_SEPARATOR = '|';

    /**
     * Placas por id de vehículo. Los ids sin placa conocida no aparecen en el mapa.
     *
     * @param  list<string>          $vehicleIds
     * @return array<string, string>
     */
    public function platesFor(array $vehicleIds): array;

    /**
     * Nombre del chofer por (vehículo, día). La clave del mapa es vehicleId.SLOT_SEPARATOR.planDate; los
     * pares sin chofer conocido no aparecen.
     *
     * @param  list<array{vehicle_id: string, plan_date: string}> $slots
     * @return array<string, string>
     */
    public function driverNamesFor(array $slots): array;
}
