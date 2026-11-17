<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Outbox;

use Illuminate\Database\ConnectionInterface;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Reactor outbox: cuando ejecución cierra un tramo del envío (`execution.shipment_leg_closed`, al llegar a un
 * hub intermedio o al hacer el handoff a la transportadora), este contexto —dueño de los tramos— pasa ese
 * tramo a `closed` con su `arrived_at`, y arranca (`in_transit`) el siguiente tramo pendiente.
 *
 * Idempotente por (shipment_id, seq): reaplicar deja los mismos valores. NO reemite eventos al outbox (es una
 * proyección del estado del tramo). Tolerante: ante datos mal formados o fallo de base, no hace nada y nunca
 * lanza (no traba el relay).
 */
final readonly class CloseLegOnShipmentLegClosed implements MessageDispatcher
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'execution.shipment_leg_closed') {
            return;
        }

        $payload = $message->payload();
        $shipmentId = $payload['shipment_id'] ?? null;
        $seq = $payload['seq'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;

        if (!is_string($shipmentId) || trim($shipmentId) === '' || !is_numeric($seq)) {
            return;
        }

        $shipmentId = trim($shipmentId);
        $seq = (int) $seq;
        $arrivedAt = is_string($occurredAt) && trim($occurredAt) !== '' ? trim($occurredAt) : null;

        try {
            // Cierra el tramo alcanzado.
            $this->connection->table('shipment_legs')
                ->where('shipment_id', $shipmentId)
                ->where('seq', $seq)
                ->update(['status' => 'closed', 'arrived_at' => $arrivedAt, 'updated_at' => $arrivedAt]);

            // Arranca el siguiente tramo pendiente (si lo hay): pasa a en tránsito con su salida.
            $this->connection->table('shipment_legs')
                ->where('shipment_id', $shipmentId)
                ->where('seq', $seq + 1)
                ->where('status', 'pending')
                ->update(['status' => 'in_transit', 'departed_at' => $arrivedAt, 'updated_at' => $arrivedAt]);
        } catch (Throwable) {
            // Proyección best-effort: un fallo no traba el relay; el reintento del outbox la vuelve a aplicar.
        }
    }
}
