<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

use Logistics\Shipments\Application\Scope\ShipmentScopeActor;

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
     * @param list<string>|null $shipmentIds
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
        // Aditivo: ciudad — un código de departamento (`la_paz`, `santa_cruz`, …) filtra por el departamento
        // de DESTINO; cualquier otro texto coincide con la ciudad o la dirección del envío.
        public ?string $city = null,
        // Aditivo: solo envíos asignados a ese vehículo (entrega abierta o parada en su ruta).
        public ?string $vehicleId = null,
        // Aditivo: solo estos envíos (ids ya resueltos y con forma de UUID; lo usa la consulta de pedidos).
        // Null = sin filtro; lista vacía = ningún envío.
        public ?array $shipmentIds = null,
        // Alcance por rol de quien lista (ciudad / tienda / asignación). Null = sin alcance (procesos internos
        // y la consulta abierta de pedidos).
        public ?ShipmentScopeActor $scope = null,
        // ¿`search` también mira el nombre y el documento del cliente? True en el listado (comportamiento de
        // siempre); la consulta abierta de pedidos lo apaga para los roles que solo buscan por código.
        public bool $searchCustomerData = true,
    ) {}
}
