<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipmentsByCursor;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista envíos con paginación por CURSOR (keyset), el modo preferido para la colección de envíos (crece
 * sin techo). Mismos filtros opcionales que ListShipments (estado, fuente, nodo destino), más el tamaño
 * de página y el cursor (y el rango de fecha prometida de entrega) (`afterId` = id del último envío de la página previa; null = primera página).
 *
 * El nodo destino lo FUERZA la puerta app para el rol Tienda (server-side), igual que ListShipments.
 */
final readonly class ListShipmentsByCursor implements Query
{
    /**
     * @param list<string>|null $statuses
     */
    public function __construct(
        public int $limit,
        public ?string $afterId = null,
        public ?string $status = null,
        public ?string $source = null,
        public ?string $destinationNodeId = null,
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
