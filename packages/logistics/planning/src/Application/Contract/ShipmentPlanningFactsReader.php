<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

use Logistics\Planning\Application\ReadModel\ShipmentPlanningFacts;

/**
 * Puerto de LECTURA de los datos de envíos que usa Planificación para mostrar un plan sin identificadores
 * internos y para armar viajes (ubicación, hora elegida por el cliente, carga). Planificación no se acopla
 * al esquema de Envíos: el adaptador de Infrastructure resuelve cada dato EN LOTE (una consulta por conjunto de
 * paradas, nunca una por parada).
 */
interface ShipmentPlanningFactsReader
{
    /**
     * Datos por id de envío. Los envíos inexistentes (o los ids sin forma de UUID) no aparecen en el mapa.
     *
     * @param  list<string>                         $shipmentIds
     * @param  bool                                 $withLoad    true = incluir peso y volumen (una consulta más)
     * @return array<string, ShipmentPlanningFacts>
     */
    public function factsFor(array $shipmentIds, bool $withLoad = false): array;
}
