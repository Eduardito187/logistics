<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Settings\Application\Query\GetSetting\GetSettingQuery;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Devuelve el valor vigente de una clave a una fecha dada (query param `at`, opcional).
 *
 * Habla con el QueryBus, nunca con Infrastructure ni el dominio. Responde 404 con problem+json si la
 * clave no tiene ninguna versión vigente a esa fecha.
 */
final class GetSettingController
{
    public function __invoke(Request $request, QueryBus $bus, string $key): JsonResponse
    {
        $at = $request->query('at');
        $at = is_string($at) && $at !== '' ? $at : null;

        /** @var array<string, mixed>|null $value */
        $value = $bus->ask(new GetSettingQuery($key, $at));

        if ($value === null) {
            return new JsonResponse([
                'type' => 'https://httpstatuses.com/404',
                'title' => 'Setting not found',
                'status' => 404,
                'detail' => "No effective value for setting key '{$key}' at the requested date.",
            ], 404, ['Content-Type' => 'application/problem+json']);
        }

        return new JsonResponse([
            'data' => [
                'key' => $key,
                'value' => $value,
            ],
        ]);
    }
}
