<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\Query\GetOutboxStats;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide el conteo de mensajes por estado del outbox (tablero de operación y alertas). Sin parámetros.
 */
final readonly class GetOutboxStatsQuery implements Query {}
