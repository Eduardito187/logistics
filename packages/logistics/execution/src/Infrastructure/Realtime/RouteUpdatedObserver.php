<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho que cambia la hoja de ruta de un camión, encola la señal de
 * tiempo real `route.updated` para su tripulación.
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje. Solo ENCOLA
 * {@see BroadcastRouteUpdated}; no consulta la base ni habla con el servidor de sockets. Resolver la ruta,
 * el camión y los destinatarios es trabajo de la cola.
 *
 *  - Anti-ráfaga: varios hechos seguidos de la misma ruta se avisan una sola vez por ventana corta
 *    (`Cache::add` + el trabajo demorado esa misma ventana, para que al llegar la señal la ruta ya incluya
 *    toda la ráfaga). La clave es la ruta cuando el hecho la trae; si no, el camión (y el día) o el envío.
 *  - Verlo más de una vez (reintentos del outbox) es inocuo: la señal es «volvé a pedir tu ruta».
 *  - Nunca lanza: una carga incompleta se ignora y cualquier error queda en el log.
 *  - Sin transporte de tiempo real (`BROADCAST_CONNECTION=null|log`) no hace nada.
 */
final readonly class RouteUpdatedObserver implements OutboxObserver
{
    /**
     * Hechos que cambian lo que el chofer ve en su hoja de ruta.
     *
     * @var list<string>
     */
    public const EVENTS = [
        // Ruta: armada (replanificación), iniciada, cerrada.
        'execution.driver_route_assembled',
        'execution.driver_route_started',
        'execution.driver_route_closed',
        // Paradas: agregada, quitada, llegada, entregada, fallida.
        'execution.route_stop_attached',
        'execution.route_stop_detached',
        'execution.stop_arrived',
        'execution.route_stop_completed',
        'execution.route_stop_failed',
        // Entrega del pedido: abierta en un camión, movida de camión, preparada, cargada, en camino,
        // descargada, entregada, fallida, dejada en tienda.
        'execution.delivery_opened',
        'execution.delivery_vehicle_reassigned',
        'execution.shipment_prepared',
        'execution.shipment_loaded',
        'execution.shipment_departed',
        'execution.shipment_unloaded',
        'execution.shipment_delivered',
        'execution.delivery_failed',
        'execution.shipment_dropped_at_store',
        // Incidente del camión: empuja ETAs o reprograma las paradas pendientes.
        'execution.vehicle_incident_reported',
        // Planificación sobre rutas ya materializadas.
        'planning.plan_published',
        'planning.route_reoptimized',
        'planning.stop_removed_from_plan',
        // Tripulación: al chofer le asignaron (o cambiaron) el camión.
        'fleet.crew_assembled',
    ];

    private const KEY_PREFIX = 'execution:realtime:route-signal:';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && RouteRealtime::enabled()) {
                $this->signal($message->payload());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signal(array $payload): void
    {
        $routeId = self::uuid($payload['route_id'] ?? null);
        $shipmentId = self::uuid($payload['shipment_id'] ?? null);
        $date = self::date($payload['route_date'] ?? $payload['plan_date'] ?? $payload['assembled_for'] ?? null);

        $vehicleIds = [];
        foreach (['vehicle_id', 'from_vehicle_id', 'to_vehicle_id'] as $field) {
            $vehicleId = self::uuid($payload[$field] ?? null);
            if ($vehicleId !== null && !in_array($vehicleId, $vehicleIds, true)) {
                $vehicleIds[] = $vehicleId;
            }
        }

        $key = self::key($routeId, $vehicleIds, $shipmentId, $date);
        $seconds = RouteRealtime::debounceSeconds();

        // Sin ruta, camión ni envío no hay a quién avisar; dentro de la ventana ya hay una señal en camino.
        if ($key === null || !Cache::add($key, true, $seconds)) {
            return;
        }

        BroadcastRouteUpdated::dispatch($routeId, $vehicleIds, $shipmentId, $date)->delay($seconds);
    }

    /**
     * Clave de la ventana anti-ráfaga: lo más específico que traiga el hecho.
     *
     * @param list<string> $vehicleIds
     */
    private static function key(?string $routeId, array $vehicleIds, ?string $shipmentId, ?string $date): ?string
    {
        if ($routeId !== null) {
            return self::KEY_PREFIX.'route:'.$routeId;
        }

        if ($vehicleIds !== []) {
            return self::KEY_PREFIX.'vehicle:'.implode('+', $vehicleIds).':'.($date ?? 'today');
        }

        return $shipmentId !== null ? self::KEY_PREFIX.'shipment:'.$shipmentId : null;
    }

    /**
     * Solo UUIDs con forma válida: en la cola se comparan contra columnas `uuid` y PostgreSQL rechaza
     * cualquier otro texto.
     */
    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * Día (`Y-m-d`) de una fecha que puede venir sola o con hora.
     */
    private static function date(mixed $value): ?string
    {
        $day = is_string($value) ? substr($value, 0, 10) : '';

        return preg_match(self::DATE_PATTERN, $day) === 1 ? $day : null;
    }
}
