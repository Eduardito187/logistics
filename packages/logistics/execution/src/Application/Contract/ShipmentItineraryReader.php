<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Logistics\Execution\Application\ReadModel\ItineraryLeg;

/**
 * Puerta de LECTURA del itinerario multi-tramo de un envío (tabla `shipment_legs`, de otro contexto).
 *
 * La ejecución la usa para la correctitud multi-tramo: dejar la carga en un nodo INTERMEDIO (un `to_node_id`
 * que NO es el destino final del itinerario) cierra el TRAMO y el envío sigue; solo el destino final (o la
 * entrega a domicilio con prueba de entrega) cierra la entrega como entregada.
 *
 * Todo en primitivos y tolerante: un envío sin tramos (modelo de UN solo tramo) devuelve el destino final
 * null / "no es intermedio" / sin tramo abierto, de modo que el comportamiento histórico queda intacto.
 *
 * Al extraer ejecución a un servicio aparte, esta puerta pasa a llamar a la API de envíos; el contrato de
 * Application no cambia.
 */
interface ShipmentItineraryReader
{
    /**
     * Nodo destino FINAL del itinerario (el `to_node_id` del último tramo). Null si el envío no tiene
     * itinerario multi-tramo, si el último tramo es entrega a domicilio (`to_node_id` null) o si no existe.
     */
    public function finalDestinationNodeId(string $shipmentId): ?string;

    /**
     * ¿El nodo es un HUB INTERMEDIO del itinerario? Verdadero solo si el envío tiene varios tramos y el nodo
     * es el `to_node_id` de un tramo que NO es el último (llegar ahí cierra el tramo, no la entrega). Falso
     * para un envío de un solo tramo, para el destino final y para un nodo ajeno al itinerario.
     */
    public function isIntermediateHub(string $shipmentId, string $nodeId): bool;

    /**
     * Primer tramo todavía ABIERTO (status `pending` o `in_transit`), por orden de `seq`: el que el envío
     * está recorriendo. Null si no hay tramos o todos están cerrados.
     */
    public function nextOpenLeg(string $shipmentId): ?ItineraryLeg;
}
