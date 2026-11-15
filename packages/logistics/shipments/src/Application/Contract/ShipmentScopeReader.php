<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentOriginView;
use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

/**
 * Lado de LECTURA del alcance por rol de los envíos. Es el ÚNICO lugar donde vive la regla de qué envíos
 * puede listar y operar un actor: la implementación la expresa como un predicado SQL, y ese mismo predicado
 * lo usan el listado (filtro), la consulta de pedidos (`can_process`) y la guarda de escrituras (403).
 *
 * Cada método trabaja en lote sobre un puñado de ids, con una sola sentencia y solo primitivos.
 */
interface ShipmentScopeReader
{
    /**
     * De los envíos dados, los que el actor puede operar (los ids sin forma de UUID se ignoran).
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    public function inScope(ShipmentScopeActor $actor, array $shipmentIds): array;

    /**
     * De los envíos dados, los que existen.
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    public function existing(array $shipmentIds): array;

    /**
     * Envíos de un pedido por su número (referencia de la orden o código externo; un pedido con varios
     * destinos devuelve sus envíos hermanos).
     *
     * @return list<string>
     */
    public function shipmentIdsOfOrder(string $orderNumber): array;

    /**
     * Origen y departamentos de cada envío, con el nombre legible del nodo de origen (los envíos
     * desconocidos no aparecen).
     *
     * @param  list<string>                      $shipmentIds
     * @return array<string, ShipmentOriginView>
     */
    public function origins(array $shipmentIds): array;
}
