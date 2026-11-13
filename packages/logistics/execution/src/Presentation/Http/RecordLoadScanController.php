<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Presentation\Http\Request\LoadScanRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * POST /app — registra un escaneo de carga validada del producto al vehículo (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (preparada).
 */
final class RecordLoadScanController extends ExecutionController
{
    public function __invoke(LoadScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // El producto se identifica escaneando su ETIQUETA: normaliza `unit_id` (label_id → bulto real).
        $data = $this->resolveScannedUnitIds($data);

        $this->commandBus->dispatch(ScanCommandFactory::load(
            $data,
            $this->deviceId($request),
            $this->userId($request),
            $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse((string) $data['shipment_id']);
    }
}
