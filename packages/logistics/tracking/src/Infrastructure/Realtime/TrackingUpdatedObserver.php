<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Realtime;

use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho del seguimiento del pedido (cambió el estado visible, el avance
 * por producto o la ruta que lo transporta), encola la señal de tiempo real `tracking.updated` de ese pedido.
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje. Solo ENCOLA
 * {@see BroadcastTrackingUpdated}; no consulta la base ni habla con el servidor de sockets. Resolver el
 * estado vigente, la referencia de la orden y las ciudades es trabajo de la cola.
 *
 *  - Anti-ráfaga: varios hechos seguidos del mismo pedido se avisan una sola vez por ventana corta
 *    (`Cache::add` + el trabajo demorado esa misma ventana, para que al llegar la señal el estado vigente ya
 *    incluya la ráfaga completa). La clave es el envío.
 *  - Verlo más de una vez (reintentos del outbox) es inocuo: la señal es «volvé a pedir el estado».
 *  - Nunca lanza: una carga sin envío se ignora y cualquier error queda en el log.
 *  - Sin transporte de tiempo real (`BROADCAST_CONNECTION=null|log`) no hace nada.
 *
 * Cada hecho de seguimiento lleva el `shipment_id` del envío en su carga: `tracking.status_changed` (cubre
 * procesando/en camino/entregado/fallido/cancelado/reprogramado/devuelto, cada transición del estado
 * visible), `tracking.shipment_placed_on_route` (la ruta que transporta el pedido cambió) y
 * `tracking.unit_status_changed` (avance por producto). Un `status_changed` sin envío ligado todavía (p. ej.
 * procesando antes de crear el envío) no tiene ficha a la que avisar: se ignora.
 */
final readonly class TrackingUpdatedObserver implements OutboxObserver
{
    /**
     * Hechos del seguimiento que disparan la señal al pedido.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'tracking.status_changed',
        'tracking.shipment_placed_on_route',
        'tracking.unit_status_changed',
    ];

    private const KEY_PREFIX = 'tracking:realtime:signal:';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && TrackingRealtime::enabled()) {
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
        $shipmentId = self::uuid($payload['shipment_id'] ?? null);
        $seconds = TrackingRealtime::debounceSeconds();

        // Sin envío no hay ficha ni ciudad a la que avisar; dentro de la ventana ya hay una señal en camino.
        if ($shipmentId === null || !Cache::add(self::KEY_PREFIX.$shipmentId, true, $seconds)) {
            return;
        }

        BroadcastTrackingUpdated::dispatch($shipmentId, $eventName)->delay($seconds);
    }

    /**
     * Solo UUIDs con forma válida: en la cola se comparan contra columnas `uuid` y PostgreSQL rechaza
     * cualquier otro texto.
     */
    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
