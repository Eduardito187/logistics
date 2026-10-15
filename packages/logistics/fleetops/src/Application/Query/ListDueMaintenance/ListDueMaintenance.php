<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Query\ListDueMaintenance;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los planes de mantenimiento preventivo por vencer o vencidos a hoy (según los umbrales de config).
 */
final readonly class ListDueMaintenance implements Query {}
