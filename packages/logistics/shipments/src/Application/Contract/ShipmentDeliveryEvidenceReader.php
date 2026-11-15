<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentDeliveryEvidenceView;

/**
 * Lado de LECTURA de la evidencia de entrega de los envíos (firma / foto / documento).
 *
 * Contrato de Application; la implementación lee en solo lectura las tablas de ejecución (sin acoplarse a
 * su dominio). Debe resolver la página COMPLETA en un número fijo de consultas por lote (whereIn), nunca
 * una consulta por envío (sin N+1).
 */
interface ShipmentDeliveryEvidenceReader
{
    /**
     * Evidencia de entrega de cada envío pedido, indexada por id. Se pide SOLO para envíos ya entregados:
     * cada id pedido aparece en el resultado (con los tres indicadores en false si no quedó evidencia).
     * Tolerante: si una fuente no está disponible, sus indicadores quedan en false.
     *
     * @param  list<string>                                $shipmentIds
     * @return array<string, ShipmentDeliveryEvidenceView>
     */
    public function forDeliveredShipmentIds(array $shipmentIds): array;
}
