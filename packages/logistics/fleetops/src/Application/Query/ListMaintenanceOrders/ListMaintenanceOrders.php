<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListMaintenanceOrders;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista órdenes de mantenimiento (más recientes primero). Filtros opcionales: vehículo, estado y rango de
 * la fecha agendada (ISO 8601). Con `limit` el handler pagina en SQL y devuelve una Page; sin `limit`, la
 * lista completa (compatibilidad).
 */
final readonly class ListMaintenanceOrders implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $status = null,
        public ?string $from = null,
        public ?string $to = null,
        public ?int $limit = null,
        public ?int $offset = null,
    ) {}
}
