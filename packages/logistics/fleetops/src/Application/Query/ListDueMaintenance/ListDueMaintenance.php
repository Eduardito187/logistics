<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDueMaintenance;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los planes de mantenimiento preventivo por vencer o vencidos a hoy (según los umbrales de config).
 *
 * Filtros opcionales: un vehículo (el chofer solo ve el suyo) y la urgencia ('overdue' | 'due_soon').
 */
final readonly class ListDueMaintenance implements Query
{
    public function __construct(
        public ?string $vehicleId = null,
        public ?string $urgency = null,
    ) {}
}
