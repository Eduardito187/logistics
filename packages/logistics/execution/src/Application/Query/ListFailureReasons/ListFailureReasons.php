<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListFailureReasons;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista TODOS los motivos del catálogo (activos e inactivos), ordenados por sort_order. Para el admin.
 */
final readonly class ListFailureReasons implements Query {}
