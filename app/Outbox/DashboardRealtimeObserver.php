<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Jobs\BroadcastDashboardUpdated;
use App\Support\Realtime\RealtimeScopeReader;
use App\Support\Staff\StaffInbox;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho de EJECUCIÓN que mueve una métrica del tablero del día, encola la
 * señal de tiempo real `dashboard.updated` de esa fecha ({@see BroadcastDashboardUpdated}).
 *
 * Qué la cambia (mapa hecho → señal):
 *  - `execution.shipment_delivered`   : se registró una entrega (incluye el retiro en mostrador);
 *  - `execution.delivery_failed`      : falló un intento de entrega;
 *  - `execution.driver_route_started` : un chofer inició su ruta del día;
 *  - `execution.driver_route_closed`  : un chofer cerró su ruta del día;
 *  - `execution.shipment_loaded`      : avance de carga (se cargó un pedido al vehículo).
 *
 * Fecha (`plan_date`, `Y-m-d`): la del hecho (`route_date` de la ruta, o la fecha de `occurred_at`); si el
 * hecho no la trae, el día de hoy. Dimensión: la CIUDAD del hecho ({@see RealtimeScopeReader}) — del envío si
 * lo trae, o del nodo base del vehículo para los hechos de ruta; si no se resuelve, el canal general
 * (`operations.all`, oficina).
 *
 * Observador (no reactor): no participa del desenlace del mensaje. Anti-ráfaga por FECHA + ciudad, ventana de
 * ~5 s (el tablero tolera un refresco cada pocos segundos). Nunca lanza; sin transporte de tiempo real no hace nada.
 */
final readonly class DashboardRealtimeObserver implements OutboxObserver
{
    /**
     * Hechos que disparan la señal del tablero.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'execution.shipment_delivered',
        'execution.delivery_failed',
        'execution.driver_route_started',
        'execution.driver_route_closed',
        'execution.shipment_loaded',
    ];

    private const string KEY_PREFIX = 'dashboard:realtime:signal:';

    /** Ventana (segundos) en la que varios cambios de la misma fecha/ciudad se avisan una sola vez. */
    private const int DEBOUNCE_SECONDS = 5;

    public function __construct(
        private RealtimeScopeReader $reader,
    ) {}

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && StaffInbox::realtimeEnabled()) {
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
        $shipmentId = self::str($payload['shipment_id'] ?? null);
        $planDate = self::date($payload['route_date'] ?? ($payload['occurred_at'] ?? null));
        // Ciudad del hecho: la del envío si lo trae; para los hechos de ruta (que llevan el vehículo, no el
        // envío) se resuelve por el nodo base del vehículo, así el planificador los recibe por su canal de ciudad.
        $city = $shipmentId !== null
            ? ($this->reader->shipmentScope($shipmentId)['city'] ?? null)
            : $this->reader->vehicleCity(self::str($payload['vehicle_id'] ?? null));

        // Dentro de la ventana ya hay una señal en camino para esta fecha/ciudad.
        if (!Cache::add(self::KEY_PREFIX.$planDate.':'.($city ?? 'all'), true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        BroadcastDashboardUpdated::dispatch($planDate, $city)->delay(self::DEBOUNCE_SECONDS);
    }

    /**
     * Fecha `Y-m-d` del valor (acepta `Y-m-d` y marcas ATOM); si falta o no se entiende, el día de hoy.
     */
    private static function date(mixed $value): string
    {
        if (is_string($value) && trim($value) !== '') {
            try {
                return Carbon::parse($value)->format('Y-m-d');
            } catch (Throwable) {
                // Valor ilegible: cae a hoy.
            }
        }

        return Carbon::now()->format('Y-m-d');
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
