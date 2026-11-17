<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Contract;

/**
 * Convierte un (origen, destino, modo de acarreo) en la SECUENCIA DE TRAMOS (legs) que lo materializa.
 * Es el insumo que la ola de planning mapea a los tramos del envío (ShipmentLeg del package shipments).
 *
 * network NO decide el modo de acarreo (eso lo decide planning) ni elige el camión: solo arma la
 * secuencia de tramos según el modelo Dismac:
 *
 *  - PROPIA, mismo departamento → 1 tramo: origen → destino (nodo o cliente), own_fleet.
 *  - PROPIA, interdepartamental → 2 tramos: origen → hub del departamento destino (own_fleet), y luego
 *    hub destino → destino final (own_fleet).
 *  - TERCERIZADO → 2 tramos: origen → almacén de la transportadora (own_fleet, nuestro handoff corto) y
 *    luego almacén transportadora → destino final (third_party, con carrier_id).
 *
 * El acarreo (`haulage_mode`) de CADA tramo es 'own_fleet' o 'third_party' (no el modo a nivel envío):
 * así lo consume shipments sin traducción.
 */
interface ItineraryPlanner
{
    /**
     * Arma la secuencia de tramos de un envío.
     *
     * El último tramo termina en `to_node_id = $destinationNodeId` cuando el destino es un nodo de la red
     * (tienda, punto de recojo), o en `to_node_id = null` cuando es una entrega a domicilio (cliente por
     * geolocalización, `$customerGeo`).
     *
     * @param  string                $originNodeId          id del nodo de origen (un CD/almacén)
     * @param  string|null           $destinationNodeId     id del nodo destino, o null si es a domicilio
     * @param  string                $destinationDepartment código o nombre del departamento destino
     * @param  array{lat: float, lng: float}|null $customerGeo coordenadas del cliente (entrega a domicilio)
     * @param  string                $haulageMode           'own_fleet' | 'outsourced' (decisión de planning)
     * @param  string|null           $carrierId             id de la transportadora cuando es tercerizado
     * @param  string|null           $carrierHubNodeId      almacén de la transportadora ya elegido (opcional);
     *                                                       si es null se resuelve por el directorio
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
