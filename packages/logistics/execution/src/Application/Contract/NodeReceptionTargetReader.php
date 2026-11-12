<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerta de lectura para DERIVAR en el servidor a qué nodo llega un envío, desde qué nodo sale y qué
 * productos se esperan, cuando la app abre una recepción mandando solo el envío (la app ya no elige el
 * nodo de origen ni arma el manifiesto).
 *
 * Esos datos viven en otros contextos (shipments, fleet, network): la implementación los lee read-only con
 * el query builder, en primitivos, sin acoplar el dominio de ejecución. Tolerante a datos ausentes.
 */
interface NodeReceptionTargetReader
{
    /**
     * Datos del envío para la recepción: nodo destino (puede no estar poblado), ids de sus productos
     * físicos (en orden estable) y el nodo base del vehículo que lo transporta, si ya tiene uno asignado.
     * Null si el envío no existe.
     *
     * @return array{destination_node_id: string|null, unit_ids: list<string>, vehicle_home_node_id: string|null}|null
     */
    public function shipment(string $shipmentId): ?array;

    /**
     * Nodo de origen por defecto hacia un destino: el centro de distribución que abastece ese nodo por la
     * red (tramo activo hacia el destino) o, si no hay tramo, el centro de distribución activo principal.
     * Nunca devuelve el propio destino. Null si la red no tiene ningún centro de distribución.
     */
    public function defaultOriginFor(string $destinationNodeId): ?string;

    /**
     * Tipo de cada nodo a efectos de la recepción: 'CD' (centro de distribución / almacén) o 'STORE'
     * (tienda / punto de retiro). Los ids que no existen no aparecen en el mapa.
     *
     * @param  list<string>          $nodeIds
     * @return array<string, string> node id => 'CD'|'STORE'
     */
    public function nodeKinds(array $nodeIds): array;
}
