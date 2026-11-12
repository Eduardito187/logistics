<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Presentation\Http\Request\FailedDeliveryRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * POST /app — registra un intento de entrega fallido con su motivo y cierra la entrega (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordFailedDeliveryController extends ExecutionController
{
    public function __invoke(FailedDeliveryRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(ScanCommandFactory::failed(
            $data,
            $this->deviceId($request),
            $this->userId($request),
            $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse((string) $data['shipment_id']);
    }
}
