<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipments;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista envíos, opcionalmente filtrados por estado y/o fuente (ambos como el valor del enum, o null), por
 * nodo destino (UUID de un nodo de red, o null para no filtrar) y por rango de fecha prometida de entrega
 * (`delivery_date` entre `$deliveryDateFrom` y `$deliveryDateTo`, inclusive; un envío sin fecha queda
 * fuera cuando se pide alguna cota).
 *
 * El filtro por nodo destino lo FUERZA la puerta app para el rol Tienda (server-side): acota el listado
 * a los envíos de su propia tienda. Ver ListShipmentsController.
 *
 * `$limit`/`$offset` son opcionales (null = sin recorte, devuelve la colección completa, para los
 * consumidores que aún no paginan). Cuando el controller los setea, el handler baja LIMIT/OFFSET a SQL
 * y devuelve una Page (ítems + total) en vez de la lista.
 */
final readonly class ListShipments implements Query
{
    /**
     * @param list<string>|null $statuses
     */
    public function __construct(
        public ?string $status = null,
        public ?string $source = null,
        public ?string $destinationNodeId = null,
        public ?int $limit = null,
        public ?int $offset = null,
        // Rango de fecha prometida de entrega (Y-m-d, ambos inclusive); null = sin cota.
        public ?string $deliveryDateFrom = null,
        public ?string $deliveryDateTo = null,
        // Aditivo: VARIOS estados a la vez (status[]=…), cualquier capitalización; null = sin filtro.
        public ?array $statuses = null,
        // Aditivo: modalidad de entrega derivada (home_delivery / store_pickup / transfer); null = sin filtro.
        public ?string $deliveryMode = null,
        // Aditivo (#1b): solo envíos asignados (PLANNED / IN_EXECUTION) aún SIN escaneo de preparación.
        public bool $pendingPreparation = false,
        // Aditivo: búsqueda por texto (código de pedido, nombre o documento del cliente); null = sin filtro.
        public ?string $search = null,
        // Aditivo: solo pedidos que YA están en su tienda listos para que el cliente los retire.
        public bool $readyForPickup = false,
    ) {}
}
