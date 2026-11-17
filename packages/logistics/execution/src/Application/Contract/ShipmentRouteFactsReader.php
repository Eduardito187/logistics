<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Hechos del envío que la ruta del día necesita para no mostrar paradas que no le corresponden y para
 * ubicar cada parada en el mapa. Solo lectura y en primitivos: no expone agregados ni modelos.
 *
 *  - Día de cierre de la entrega: un envío ya ENTREGADO (con prueba de entrega o cerrado con faltantes) en
 *    una fecha anterior al día de la ruta nunca es una parada de esa ruta.
 *  - Coordenadas del destino: las tiene el envío; la parada las copia para que el chofer la vea en el mapa.
 */
interface ShipmentRouteFactsReader
{
    /**
     * Día calendario (Y-m-d, zona de la operación) en que se cerró la entrega de cada envío ya entregado.
     * Los envíos sin entrega o con la entrega todavía abierta no aparecen.
     *
     * @param  list<string>          $shipmentIds
     * @return array<string, string> id de envío => día de cierre
     */
    public function closedDays(array $shipmentIds): array;

    /**
     * Coordenadas del destino de cada envío. Los envíos sin coordenadas no aparecen.
     *
     * @param  list<string>                                 $shipmentIds
     * @return array<string, array{lat: float, lng: float}> id de envío => coordenadas
     */
    public function coordinates(array $shipmentIds): array;
}
