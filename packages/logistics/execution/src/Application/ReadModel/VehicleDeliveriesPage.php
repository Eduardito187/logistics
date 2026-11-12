<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Página de la hoja de ruta de un vehículo: las entregas de la página y el total real del filtro (para la
 * meta estándar de ApiPagination).
 */
final readonly class VehicleDeliveriesPage
{
    /**
     * @param list<DeliveryView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
    ) {}
}
