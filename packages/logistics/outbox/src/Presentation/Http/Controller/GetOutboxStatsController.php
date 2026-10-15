<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\Query\GetOutboxStats\GetOutboxStatsQuery;
use Logistics\Outbox\Application\ReadModel\OutboxStatsView;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el conteo de mensajes por estado del outbox: insumo del tablero y de las alertas de
 * operación (backlog, dead-letter > 0). Solo lectura, vía QueryBus.
 */
final class GetOutboxStatsController
{
    public function __invoke(QueryBus $bus): JsonResponse
    {
        /** @var OutboxStatsView $stats */
        $stats = $bus->ask(new GetOutboxStatsQuery);

        return new JsonResponse(['data' => $stats->toArray()]);
    }
}
