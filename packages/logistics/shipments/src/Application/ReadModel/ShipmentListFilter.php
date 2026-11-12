<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Filtros del listado de envíos (lado de LECTURA, CQRS), agrupados en un objeto inmutable para que el
 * contrato ShipmentListReader no arrastre una lista larga de parámetros sueltos.
 *
 * Cada filtro es opcional y se combina con AND:
 *  - `status` / `source`: valor del enum ya validado (string) o null;
 *  - `destinationNodeId`: UUID del nodo destino o null;
 *  - `lowConfidenceOnly`: acota a las direcciones de confianza baja;
 *  - `deliveryDateFrom` / `deliveryDateTo`: rango de fecha prometida (Y-m-d, ambos inclusive);
 *  - `statuses`: varios estados a la vez (IN), se combina con `status` por AND;
 *  - `deliveryMode`: modalidad derivada del tipo del nodo destino (home_delivery / store_pickup).
 */
final readonly class ShipmentListFilter
{
    /**
     * @param list<string>|null $statuses
     */
    public function __construct(
        public ?string $status = null,
        public ?string $source = null,
        public ?string $destinationNodeId = null,
        public bool $lowConfidenceOnly = false,
        public ?string $deliveryDateFrom = null,
        public ?string $deliveryDateTo = null,
        public ?array $statuses = null,
        public ?string $deliveryMode = null,
    ) {}
}
