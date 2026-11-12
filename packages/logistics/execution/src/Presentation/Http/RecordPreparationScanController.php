<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Presentation\Http\Request\PreparationScanRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * POST /app — registra un escaneo de preparación de un producto (puerta app).
 *
 * Idempotente por la clave de idempotencia (header o cuerpo): el reenvío offline no duplica el hecho.
 */
final class RecordPreparationScanController extends ExecutionController
{
    public function __invoke(PreparationScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        $this->commandBus->dispatch(ScanCommandFactory::preparation(
            $data,
            $this->deviceId($request),
            $this->userId($request),
            $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse((string) $data['shipment_id']);
    }
}
