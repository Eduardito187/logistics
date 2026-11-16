<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Realtime;

use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho de las alertas de telemetría (se levantó una alerta nueva o se
 * reconoció/cerró una), encola la señal de tiempo real `alerts.updated` de esa alerta. Esos son los hechos que
 * alteran `GET /app/telemetry/alerts`: la lista muestra las alertas sin reconocer, así que reconocer una la
 * saca de la lista (equivale a cerrarla/resolverla).
 *
 * Mapa evento → señal:
 *  - `telemetry.alert_raised`       → `alerts.updated` {alert_id}
 *  - `telemetry.alert_acknowledged` → `alerts.updated` {alert_id}
 * El `alert_id` es el id del agregado del mensaje (no viene en la carga de estos eventos).
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje. Solo ENCOLA
 * {@see BroadcastAlertsUpdated}; no consulta la base ni habla con el servidor de sockets. Resolver la ciudad
 * de la alerta es trabajo de la cola.
 *
 *  - Anti-ráfaga: varios hechos seguidos de la misma alerta se avisan una sola vez por ventana corta
 *    (`Cache::add` + el trabajo demorado esa misma ventana). La clave es la alerta.
 *  - Verlo más de una vez (reintentos del outbox) es inocuo: la señal es «volvé a pedir la lista».
 *  - Nunca lanza: una carga sin alerta se ignora y cualquier error queda en el log.
 *  - Sin transporte de tiempo real (`BROADCAST_CONNECTION=null|log`) no hace nada.
 */
final readonly class AlertsUpdatedObserver implements OutboxObserver
{
    /**
     * Hechos de las alertas que disparan la señal.
     *
     * @var list<string>
     */
    public const array EVENTS = [
        'telemetry.alert_raised',
        'telemetry.alert_acknowledged',
    ];

    private const string KEY_PREFIX = 'telemetry:alerts:realtime:signal:';

    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && AlertsRealtime::enabled()) {
                $this->signal($message->aggregateId());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function signal(string $aggregateId): void
    {
        $alertId = self::uuid($aggregateId);
        $seconds = AlertsRealtime::debounceSeconds();

        // Sin alerta válida no hay a qué apuntar; dentro de la ventana ya hay una señal en camino.
        if ($alertId === null || !Cache::add(self::KEY_PREFIX.$alertId, true, $seconds)) {
            return;
        }

        BroadcastAlertsUpdated::dispatch($alertId)->delay($seconds);
    }

    /**
     * Solo UUIDs con forma válida: en la cola se comparan contra columnas `uuid` y PostgreSQL rechaza
     * cualquier otro texto.
     */
    private static function uuid(string $value): ?string
    {
        return preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
