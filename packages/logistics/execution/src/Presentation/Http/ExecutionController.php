<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReception;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Application\ReadModel\NodeReceptionView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Base de los controllers HTTP del contexto Ejecución.
 *
 * La capa de Presentation solo habla con Application a través de los buses CQRS: nunca con
 * Infrastructure ni con el dominio directo. Aquí viven el envelope de respuesta común y la
 * resolución del actor (usuario + dispositivo) para dejar los endpoints concretos limpios.
 */
abstract class ExecutionController
{
    public function __construct(
        protected readonly CommandBus $commandBus,
        protected readonly QueryBus $queryBus,
    ) {}

    /**
     * Resuelve la clave de idempotencia: primero el header `Idempotency-Key` (la app offline la fija
     * por escaneo), con respaldo en el cuerpo. Es un contrato con la app: el reenvío del mismo
     * escaneo no duplica el hecho.
     */
    protected function idempotencyKey(Request $request): string
    {
        $header = $request->header('Idempotency-Key');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $body = $request->input('idempotency_key');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Identidad del dispositivo que origina el escaneo.
     *
     * El integrador de Auth la inyecta desde el token (usuario + dispositivo) como atributos de la
     * request; mientras tanto se acepta del cuerpo para no bloquear a la app.
     */
    protected function deviceId(Request $request): string
    {
        $attribute = $request->attributes->get('device_id');
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $body = $request->input('device_id');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Identidad del usuario que origina el escaneo (ver deviceId()).
     */
    protected function userId(Request $request): string
    {
        $attribute = $request->attributes->get('user_id');
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $body = $request->input('user_id');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Respuesta estándar tras un escaneo: devuelve el estado actual de la entrega del envío.
     *
     * Así la app offline reconcilia su vista local con la fuente de verdad en una sola llamada.
     */
    protected function deliveryStateResponse(string $shipmentId, int $status = 200): JsonResponse
    {
        /** @var DeliveryView|null $view */
        $view = $this->queryBus->ask(new GetDeliveryState($shipmentId));

        return new JsonResponse(['data' => $view?->toArray()], $status);
    }

    /**
     * Respuesta estándar tras una operación de recepción en nodo: devuelve el estado actual de la
     * recepción del envío en ese nodo (esperados, recibidos, faltantes, sobrantes) en una sola llamada.
     */
    protected function nodeReceptionResponse(string $shipmentId, string $destinationNodeId, int $status = 200): JsonResponse
    {
        /** @var NodeReceptionView|null $view */
        $view = $this->queryBus->ask(new GetNodeReception($shipmentId, $destinationNodeId));

        return new JsonResponse(['data' => $view?->toArray()], $status);
    }
}
