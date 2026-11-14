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
 *  - `deliveryMode`: modalidad derivada de la fuente y del tipo del nodo destino (home_delivery /
 *    store_pickup / transfer);
 *  - `pendingPreparation`: solo envíos asignados (PLANNED / IN_EXECUTION) cuya entrega en ejecución aún no
 *    tiene escaneo de PREPARACIÓN (o no tiene entrega todavía). Incluye los que ya tienen etiquetas emitidas
 *    pero no se prepararon;
 *  - `search`: texto libre que busca por código de pedido, nombre o documento del cliente;
 *  - `readyForPickup`: solo pedidos cuya recepción en la tienda destino ya se concilió y que todavía no se
 *    entregaron (el cliente puede pasar a retirarlos).
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
        public bool $pendingPreparation = false,
        // Aditivo: búsqueda por texto (código de pedido, nombre o documento del cliente); null = sin filtro.
        public ?string $search = null,
        // Aditivo: solo pedidos que YA están en su tienda listos para que el cliente los retire.
        public bool $readyForPickup = false,
    ) {}
}
