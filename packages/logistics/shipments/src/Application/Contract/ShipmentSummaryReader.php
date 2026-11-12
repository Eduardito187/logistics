<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

use Logistics\Shipments\Application\ReadModel\ShipmentSummaryView;

/**
 * Lado de LECTURA agregado de envíos (CQRS): resumen de tablero y conteos por estado, con agregados SQL
 * (GROUP BY / COUNT) sobre columnas indexadas, sin materializar filas. La implementación puede cachear
 * unos segundos la forma primitiva del resultado.
 */
interface ShipmentSummaryReader
{
    /**
     * Resumen del tablero. Los envíos se acotan por fecha PROMETIDA (`$deliveryDateFrom`/`$deliveryDateTo`,
     * Y-m-d inclusive; null = sin cota), nodo destino y modalidad de entrega. Planes publicados y alertas
     * abiertas se acotan al PERÍODO operativo (`$periodFrom`..`$periodTo`, Y-m-d, zona America/La_Paz).
     */
    public function summary(
        ?string $deliveryDateFrom,
        ?string $deliveryDateTo,
        ?string $destinationNodeId,
        ?string $deliveryMode,
        string $periodFrom,
        string $periodTo,
    ): ShipmentSummaryView;

    /**
     * Cantidad de envíos por estado (todos los estados, con 0) para los filtros dados (sin filtro de
     * estado: sirve para las pestañas del listado).
     *
     * @return array<string, int>
     */
    public function countByStatus(
        ?string $source,
        ?string $destinationNodeId,
        ?string $deliveryDateFrom,
        ?string $deliveryDateTo,
        ?string $deliveryMode,
    ): array;
}
