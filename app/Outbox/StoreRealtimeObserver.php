<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Jobs\BroadcastStoreUpdated;
use App\Support\Realtime\RealtimeScopeReader;
use App\Support\Staff\StaffInbox;
use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho que cambia lo que ve una TIENDA, encola la señal de tiempo real
 * `store.updated` del nodo ({@see BroadcastStoreUpdated}).
 *
 * Qué la cambia (mapa hecho → señal):
 *  - `execution.shipment_dropped_at_store`  : el chofer dejó el pedido en la tienda (nodo en la carga);
 *  - `execution.node_reception_reconciled`  : se concilió la recepción en el nodo (nodo en la carga);
 *  - `execution.node_unit_received`         : se escaneó la recepción de un bulto en el nodo (nodo en la carga);
 *  - `execution.shipment_delivered`         : entrega del pedido (incluye el retiro en mostrador), nodo del envío;
 *  - `shipments.shipment_rescheduled`       : se reprogramó un pedido, nodo del envío;
 *  - `orders.order_cancelled`               : se canceló un pedido, nodos de los envíos de ese pedido.
 *
 * Dimensión: el NODO destino. Lo trae la carga del hecho cuando existe; si no, se resuelve SOLO lectura por el
 * query builder desde el envío o la referencia de pedido ({@see RealtimeScopeReader}). Si no hay nodo que
 * resolver, no se emite (nunca se avisa a una tienda que no tiene que ver con el hecho).
 *
 * Observador (no reactor): no participa del desenlace del mensaje. Anti-ráfaga por NODO. Nunca lanza; sin
 * transporte de tiempo real no hace nada.
 */
final readonly class StoreRealtimeObserver implements OutboxObserver
{
    /**
     * Hechos que disparan la señal de tienda.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'execution.shipment_dropped_at_store',
        'execution.node_reception_reconciled',
        'execution.node_unit_received',
        'execution.shipment_delivered',
        'shipments.shipment_rescheduled',
        'orders.order_cancelled',
    ];

    private const string KEY_PREFIX = 'store:realtime:signal:';

    /** Ventana (segundos) en la que varios cambios del mismo nodo se avisan una sola vez. */
    private const int DEBOUNCE_SECONDS = 2;

    public function __construct(
        private RealtimeScopeReader $reader,
    ) {}

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && StaffInbox::realtimeEnabled()) {
                $this->signal($message->eventName(), $message->payload());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signal(string $eventName, array $payload): void
    {
        $shipmentId = self::str($payload['shipment_id'] ?? null);

        foreach ($this->nodesFor($eventName, $payload, $shipmentId) as $node) {
            if (Cache::add(self::KEY_PREFIX.$node, true, self::DEBOUNCE_SECONDS)) {
                BroadcastStoreUpdated::dispatch($node, $shipmentId)->delay(self::DEBOUNCE_SECONDS);
            }
        }
    }

    /**
     * Nodos destino afectados por el hecho: el de la carga si viene; para una cancelación, los de los envíos
     * del pedido; si no, el nodo del envío. La validación de UUID la hace el lector.
     *
     * @param  array<string, mixed> $payload
     * @return list<string>
     */
    private function nodesFor(string $eventName, array $payload, ?string $shipmentId): array
    {
        $direct = self::str($payload['node_id'] ?? null) ?? self::str($payload['destination_node_id'] ?? null);
        if ($direct !== null) {
            return RealtimeChannels::isUuid($direct) ? [strtolower($direct)] : [];
        }

        if ($eventName === 'orders.order_cancelled') {
            return $this->reader->orderReferenceNodes(self::str($payload['order_id'] ?? null));
        }

        return self::nodeList($shipmentId === null ? null : ($this->reader->shipmentScope($shipmentId)['node'] ?? null));
    }

    /**
     * @return list<string>
     */
    private static function nodeList(?string $node): array
    {
        return $node === null ? [] : [$node];
    }

    private static function str(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
