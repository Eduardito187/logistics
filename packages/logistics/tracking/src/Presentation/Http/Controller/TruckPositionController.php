<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Logistics\Telemetry\Application\Ingest\ShipmentPositionBridge;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Presentation\Http\ApiResponder;
use Logistics\Tracking\Presentation\Http\Request\UpdateTruckPositionRequest;
use Throwable;

/**
 * Ingesta de posición GPS en vivo del camión que lleva un envío.
 *
 * La alimenta el flujo del chofer en la app interna (puerta /api/v1/app/*) y, cuando el reporte llega
 * de telemetría externa ya normalizada, la puerta /api/v1/integrations/*. Ambas desembocan en el mismo
 * comando; la credencial y el rate limit los pone el middleware de cada puerta.
 *
 * Es una escritura de alta frecuencia (~3 req/s): responde 202 sin cuerpo pesado. Un error de regla de
 * negocio del dominio (coordenada fuera de rango, seguimiento inexistente) se traduce a 422.
 *
 * Presentation habla con Application vía el CommandBus; nunca toca Infrastructure ni el dominio.
 *
 * Además de actualizar la miga de pan del envío (contexto tracking), PUENTEA la posición a la ingesta de
 * telemetría del vehículo que lo custodia (ShipmentPositionBridge), para que el motor de desvío de ruta y
 * reruteo funcione aunque la app mande el GPS por envío. El puente es best-effort: un fallo suyo nunca
 * tumba el 202 del seguimiento (que es el dato crítico del chofer).
 */
final class TruckPositionController
{
    use ApiResponder;

    public function __construct(
        private readonly CommandBus $commandBus,
        private readonly ShipmentPositionBridge $telemetryBridge,
    ) {}

    public function store(UpdateTruckPositionRequest $request, string $shipmentId): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new UpdateTruckPosition(
                shipmentId: $shipmentId,
                latitude: $request->latitude(),
                longitude: $request->longitude(),
                reportedAt: $request->reportedAt(),
                speedMps: $request->speedMps(),
                headingDeg: $request->headingDeg(),
                accuracyM: $request->accuracyM(),
            ));
        } catch (DomainException $exception) {
            return $this->error('position_rejected', $exception->getMessage(), 422);
        }

        try {
            $this->telemetryBridge->bridge(
                shipmentId: $shipmentId,
                latitude: $request->latitude(),
                longitude: $request->longitude(),
                reportedAt: $request->reportedAt(),
                speedMps: $request->speedMps(),
                headingDeg: $request->headingDeg(),
                accuracyM: $request->accuracyM(),
            );
        } catch (Throwable $exception) {
            // El puente a telemetría es best-effort: nunca debe tumbar el acuse del seguimiento.
            report($exception);
        }

        return $this->data(['shipment_id' => $shipmentId, 'accepted' => true], 202);
    }
}
