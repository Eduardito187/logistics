<?php

declare(strict_types=1);

namespace Logistics\Tracking\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\Exception\DomainException;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Logistics\Tracking\Presentation\Http\ApiResponder;
use Logistics\Tracking\Presentation\Http\Request\UpdateTruckPositionRequest;

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
 */
final class TruckPositionController
{
    use ApiResponder;

    public function __construct(
        private readonly CommandBus $commandBus,
    ) {}

    public function store(UpdateTruckPositionRequest $request, string $shipmentId): JsonResponse
    {
        try {
            $this->commandBus->dispatch(new UpdateTruckPosition(
                shipmentId: $shipmentId,
                latitude: $request->latitude(),
                longitude: $request->longitude(),
                reportedAt: $request->reportedAt(),
            ));
        } catch (DomainException $exception) {
            return $this->error('position_rejected', $exception->getMessage(), 422);
        }

        return $this->data(['shipment_id' => $shipmentId, 'accepted' => true], 202);
    }
}
