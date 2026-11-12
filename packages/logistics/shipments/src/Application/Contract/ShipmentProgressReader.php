<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentProgressView;

/**
 * Lado de LECTURA del avance operativo de los envíos (camión, preparado, cargado, entregado, etiquetas).
 *
 * Contrato de Application; la implementación lee en solo lectura las tablas de ejecución, planificación,
 * flota y etiquetas (sin acoplarse a sus dominios). Debe resolver la página COMPLETA en un número fijo de
 * consultas por lote (whereIn), nunca una consulta por envío (sin N+1).
 */
interface ShipmentProgressReader
{
    /**
     * Avance de cada envío pedido, indexado por id. Cada envío pedido aparece en el resultado (con campos
     * null si no hay rastros). Tolerante: si una fuente no está disponible, sus campos quedan null.
     *
     * @param  list<string>                        $shipmentIds
     * @return array<string, ShipmentProgressView>
     */
    public function forShipmentIds(array $shipmentIds): array;
}
