<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Presentation\Http\Request\ReceiveShipmentRequest;
use Logistics\Shipments\Presentation\Http\Support\JsonEnvelope;

/**
 * Puerta de integraciones: ingreso único e idempotente de pedidos.
 *
 * Despacha el comando ReceiveShipment por el CommandBus (que lo envuelve en transacción + outbox).
 * Como el ingreso es idempotente por (source, external_id), reenviar el mismo pedido no duplica:
 * se responde 202 Accepted devolviendo la referencia de origen recibida.
 */
final class ReceiveShipmentController
{
    use JsonEnvelope;

    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function __invoke(ReceiveShipmentRequest $request): JsonResponse
    {
        $command = $request->toCommand();

        $this->commandBus->dispatch($command);

        // 202: la recepción se aceptó; el id interno del envío no se expone por esta puerta
        // (el bus no devuelve valor y el ingreso es idempotente). El consumidor referencia por origen.
        return $this->data([
            'source' => $command->source,
            'external_id' => $command->externalId,
            'status' => 'accepted',
        ], 202);
    }
}
