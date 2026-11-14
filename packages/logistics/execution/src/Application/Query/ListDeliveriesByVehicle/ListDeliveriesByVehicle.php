<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListDeliveriesByVehicle;

use Logistics\Shared\Application\Query\Query;

/**
 * Devuelve las entregas asignadas a un vehículo: la hoja de ruta del chofer. Con $limit pagina en la base
 * (LIMIT/OFFSET); sin él, la colección completa (comportamiento histórico).
 *
 * `$statuses` acota por estado de la entrega (valores de DeliveryStatus, en mayúsculas); vacío = todas. La
 * pantalla de carga del camión pide solo las abiertas para no mostrar pedidos ya entregados o fallidos.
 */
final readonly class ListDeliveriesByVehicle implements Query
{
    public function __construct(
        public string $vehicleId,
        public ?int $limit = null,
        public int $offset = 0,
        /** @var list<string> */
        public array $statuses = [],
    ) {}
}
