<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

/**
 * Puerta PROPIA de shipments para armar la secuencia de tramos (legs) de un envío a partir de su origen,
 * destino y modo de acarreo. shipments depende de ESTE contrato, no del contrato concreto del contexto
 * `network`: el adaptador de Infrastructure delega en el planificador de la red, de modo que cambiar o
 * extraer `network` a un servicio no obliga a tocar el dominio ni la aplicación de shipments.
 *
 * Devuelve el MISMO shape de legs que el planificador de la red (ya normalizado): el `haulage_mode` de cada
 * tramo es 'own_fleet' | 'third_party' (no el modo a nivel envío), listo para mapearse a {@see \Logistics\Shipments\Domain\Model\ShipmentLeg}.
 */
interface ItineraryRouter
{
    /**
     * Arma la secuencia de tramos de un envío. El último tramo termina en `to_node_id = $destinationNodeId`
     * cuando el destino es un nodo de la red (tienda, punto de recojo), o en `to_node_id = null` cuando es
     * una entrega a domicilio (cliente por geolocalización, `$customerGeo`).
     *
     * @param  string                              $originNodeId          id del nodo de origen (un CD/almacén)
     * @param  string|null                         $destinationNodeId     id del nodo destino, o null si es a domicilio
     * @param  string                              $destinationDepartment código o nombre del departamento destino
     * @param  array{lat: float, lng: float}|null  $customerGeo           coordenadas del cliente (entrega a domicilio)
     * @param  string                              $haulageMode           'own_fleet' | 'outsourced' (decisión de la política)
     * @param  string|null                         $carrierId             id de la transportadora cuando es tercerizado
     * @param  string|null                         $carrierHubNodeId      almacén de la transportadora ya elegido (opcional)
     * @return list<array{
     *     seq: int,
     *     from_node_id: string,
     *     to_node_id: string|null,
     *     from_department: string|null,
     *     to_department: string|null,
     *     haulage_mode: string,
     *     carrier_id: string|null
     * }>
     */
    public function planLegs(
        string $originNodeId,
        ?string $destinationNodeId,
        string $destinationDepartment,
        ?array $customerGeo,
        string $haulageMode,
        ?string $carrierId,
        ?string $carrierHubNodeId,
    ): array;
}
