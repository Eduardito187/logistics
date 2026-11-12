<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Outbox;

use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Tracking\Application\Command\UpdateTruckPosition\UpdateTruckPosition;
use Throwable;

/**
 * Reactor outbox: el lote de telemetría (`POST /app/telemetry/pings`) emite como máximo un
 * `telemetry.vehicle_position_reported` por lote con los envíos a bordo. Acá se proyecta esa posición en el
 * seguimiento de cada envío (`truck_position`), igual que el endpoint por envío de a un fix, así la app puede
 * mandar cada posición por lote sin perder el rastreo por envío.
 *
 * Idempotente y tolerante: `UpdateTruckPosition` ignora posiciones más viejas que la guardada y envíos sin
 * seguimiento; un envío que falla no frena a los demás.
 */
final readonly class TruckPositionOnVehicleReport implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'telemetry.vehicle_position_reported') {
            return;
        }

        $payload = $message->payload();
        $lat = $payload['lat'] ?? null;
        $lng = $payload['lng'] ?? null;
        $reportedAt = $payload['reported_at'] ?? null;
        $shipmentIds = $payload['shipment_ids'] ?? null;

        if (!is_numeric($lat) || !is_numeric($lng) || !is_string($reportedAt) || !is_array($shipmentIds)) {
            return;
        }

        foreach ($shipmentIds as $shipmentId) {
            if (!is_string($shipmentId) || $shipmentId === '') {
                continue;
            }

            try {
                $this->commandBus->dispatch(new UpdateTruckPosition(
                    shipmentId: $shipmentId,
                    latitude: (float) $lat,
                    longitude: (float) $lng,
                    reportedAt: $reportedAt,
                ));
            } catch (Throwable $e) {
                // Un envío sin seguimiento o con datos raros no debe frenar al resto del lote.
                report($e);
            }
        }
    }
}
