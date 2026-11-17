<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\ListActiveFailureReasons;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista solo los motivos ACTIVOS, ordenados por sort_order. Es lo que la app ofrece al chofer al marcar una
 * entrega como no realizada.
 */
final readonly class ListActiveFailureReasons implements Query {}
