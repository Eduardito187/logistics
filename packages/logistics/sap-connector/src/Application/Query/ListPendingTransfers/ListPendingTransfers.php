<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Query\ListPendingTransfers;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta de reconciliación: traspasos pendientes de empuje a SAP (Pending y Failed).
 */
final readonly class ListPendingTransfers implements Query {}
