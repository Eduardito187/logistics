<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Settings\Application\Query\GetSetting\GetSettingHistoryQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el historial completo de una clave: todas sus versiones, de la más nueva a la más vieja.
 *
 * Habla con el QueryBus; el handler devuelve una lista de arreglos primitivos (ReadModels), lista
 * para serializar. Una clave inexistente devuelve una lista vacía, no un 404.
 */
final class GetSettingHistoryController
{
    public function __invoke(QueryBus $bus, string $key): JsonResponse
    {
        /** @var list<array<string, mixed>> $history */
        $history = $bus->ask(new GetSettingHistoryQuery($key));

        return new JsonResponse([
            'data' => $history,
            'meta' => [
                'key' => $key,
                'count' => count($history),
            ],
        ]);
    }
}
