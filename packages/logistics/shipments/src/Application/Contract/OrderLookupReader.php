<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\OrderRouteFacts;

/**
 * Lado de LECTURA de la consulta abierta de pedidos (GET /app/orders/lookup): resuelve un código de pedido
 * a sus envíos y lee, en lote, los hechos de seguimiento que la consulta agrega a cada envío (si quien
 * consulta puede operarlos lo decide el lector de alcance, ShipmentScopeReader).
 *
 * Es una consulta PUNTUAL: cada método trabaja sobre un puñado de ids (el tope de la consulta), con una
 * sola sentencia por fuente y nunca una por envío. Las lecturas de otros contextos (seguimiento, ejecución,
 * red, transportistas) son de solo lectura y solo devuelven primitivos.
 */
interface OrderLookupReader
{
    /**
     * Ids de los envíos del pedido cuyo código coincide EXACTO (sin distinguir mayúsculas) con el código
     * externo o la referencia de orden; si el texto tiene forma de UUID también coincide con el id del envío.
     * Un pedido con varios envíos (misma referencia de orden) devuelve sus envíos hermanos. Orden estable por id.
     *
     * @return list<string>
     */
    public function shipmentIdsByCode(string $code, int $limit): array;

    /**
     * Hechos de ruta y seguimiento por id de envío. Cada id pedido aparece en el resultado.
     *
     * @param  list<string>                   $shipmentIds
     * @return array<string, OrderRouteFacts>
     */
    public function routeFacts(array $shipmentIds): array;

    /**
     * Nombre legible de cada nodo de red (los desconocidos no aparecen).
     *
     * @param  list<string>          $nodeIds
     * @return array<string, string>
     */
    public function nodeNames(array $nodeIds): array;
}
