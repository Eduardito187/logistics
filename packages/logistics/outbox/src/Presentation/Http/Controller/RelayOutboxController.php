<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Controller;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\JsonResponse;
use Logistics\Outbox\Application\RelayOutbox\RelayOutboxBatch;
use Logistics\Outbox\Presentation\Http\Request\RelayOutboxRequest;

/**
 * Dispara una corrida del relay bajo demanda (puerta de integraciones), además del scheduler.
 *
 * Invoca la aplicación (RelayOutboxBatch), nunca la infraestructura directa, y devuelve el resumen de
 * la corrida: tomados, despachados, fallidos y dead-letter.
 */
final class RelayOutboxController
{
    public function __invoke(RelayOutboxRequest $request, RelayOutboxBatch $relay, Repository $config): JsonResponse
    {
        $default = (int) $config->get('logistics-outbox.relay.limit', 100);
        $limit = $request->limit($default) ?? $default;

        $result = $relay->handle($limit);

        return new JsonResponse([
            'data' => [
                'pulled' => $result->pulled,
                'dispatched' => $result->dispatched,
                'failed' => $result->failed,
                'dead_lettered' => $result->deadLettered,
            ],
        ]);
    }
}
