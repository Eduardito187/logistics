<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use Illuminate\Http\JsonResponse;
use Logistics\Execution\Presentation\Http\Request\UnloadScanRequest;
use Logistics\Execution\Presentation\Http\Support\ScanCommandFactory;

/**
 * POST /app — registra la descarga guiada de un producto en una parada (puerta app).
 *
 * Idempotente por la clave de idempotencia. La entrega del envío ya debe existir (cargada o en tránsito).
 */
final class RecordUnloadScanController extends ExecutionController
{
    public function __invoke(UnloadScanRequest $request): JsonResponse
    {
        /** @var array<string, mixed> $data */
        $data = $request->validated();

        // El producto se identifica escaneando su ETIQUETA: normaliza `unit_id` (label_id → bulto real).
        $data = $this->resolveScannedUnitIds($data);

        $this->commandBus->dispatch(ScanCommandFactory::unload(
            $data,
            $this->deviceId($request),
            $this->userId($request),
            $this->idempotencyKey($request),
        ));

        return $this->deliveryStateResponse((string) $data['shipment_id']);
    }
}
