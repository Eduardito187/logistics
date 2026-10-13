<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Query\ListFailedTransfers;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta de reconciliación: traspasos que fallaron al empujarse a SAP y requieren seguimiento.
 */
final readonly class ListFailedTransfers implements Query {}
