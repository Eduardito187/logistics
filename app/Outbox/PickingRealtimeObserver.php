<?php

declare(strict_types=1);

namespace App\Outbox;

use App\Jobs\BroadcastPickingUpdated;
use App\Support\Realtime\RealtimeScopeReader;
use App\Support\Staff\StaffInbox;
use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho que cambia la lista de PREPARACIÓN de una ciudad, encola la
 * señal de tiempo real `picking.updated` ({@see BroadcastPickingUpdated}).
 *
 * Qué la cambia (mapa hecho → señal):
 *  - `planning.plan_published`            : se publicó un plan (los envíos del día quedan planificados);
 *  - `planning.stop_removed_from_plan`    : se movió un pedido entre camiones (sale del plan de origen);
 *  - `planning.route_reoptimized`         : se re-secuenció la ruta de un plan;
 *  - `shipments.shipment_received`        : entró un pedido nuevo a la lista;
 *  - `execution.delivery_opened`          : se asignó el camión = empezó a prepararse (sale de la lista);
 *  - `execution.shipment_prepared`        : se preparó el primer bulto (escaneo de preparación);
 *  - `execution.unit_prepared`            : cada bulto preparado (escaneo por bulto, en vivo);
 *  - `execution.delivery_vehicle_reassigned` : el pedido pasó a otro camión;
 *  - `labels.labels_emitted`              : se emitieron o reimprimieron las etiquetas del pedido.
 *
 * Dimensión: la CIUDAD (departamento) del pedido o del plan, resuelta SOLO lectura por el query builder
 * ({@see RealtimeScopeReader}); sin ciudad, la señal cae al canal general (`picking.all`, oficina).
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje. Anti-ráfaga por CIUDAD: varios
 * hechos seguidos de la misma ciudad se avisan una sola vez por ventana corta (`Cache::add` + el trabajo
 * demorado esa misma ventana, para que al llegar la señal la lista vigente ya incluya la ráfaga). Verlo más
 * de una vez (reintentos del outbox) es inocuo. Nunca lanza; sin transporte de tiempo real no hace nada.
 */
final readonly class PickingRealtimeObserver implements OutboxObserver
{
    /**
     * Hechos que disparan la señal de preparación.
     *
     * @var list<string>
     */
    public const EVENTS = [
        'planning.plan_published',
        'planning.stop_removed_from_plan',
        'planning.route_reoptimized',
        'shipments.shipment_received',
        'execution.delivery_opened',
        'execution.shipment_prepared',
        // Cada bulto preparado (señal liviana por escaneo): refresca la lista de preparación en vivo.
        'execution.unit_prepared',
        'execution.delivery_vehicle_reassigned',
        'labels.labels_emitted',
    ];

    private const string KEY_PREFIX = 'picking:realtime:signal:';

    /** Ventana (segundos) en la que varios cambios de la misma ciudad se avisan una sola vez. */
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
        $planId = self::str($payload['plan_id'] ?? null);
        $vehicleId = self::str($payload['vehicle_id'] ?? null) ?? self::str($payload['to_vehicle_id'] ?? null);
        $orderReference = self::str($payload['order_reference'] ?? null) ?? self::str($payload['order_number'] ?? null);

        [$city, $orderReference] = $this->resolve($shipmentId, $planId, $orderReference);

        // Dentro de la ventana ya hay una señal en camino para esta ciudad (null = canal general).
        if (!Cache::add(self::KEY_PREFIX.($city ?? 'all'), true, self::DEBOUNCE_SECONDS)) {
            return;
        }

        BroadcastPickingUpdated::dispatch($city, $shipmentId, $orderReference, $planId, $vehicleId)
            ->delay(self::DEBOUNCE_SECONDS);
    }

    /**
     * Ciudad del hecho y referencia de pedido completada: desde el envío si lo hay, si no desde el plan, si no
     * desde la referencia de pedido.
     *
     * @return array{0: string | null, 1: string | null}
     */
    private function resolve(?string $shipmentId, ?string $planId, ?string $orderReference): array
    {
        if ($shipmentId !== null) {
            $scope = $this->reader->shipmentScope($shipmentId);

            return [$scope['city'] ?? null, $orderReference ?? ($scope['order_reference'] ?? null)];
        }

        if ($planId !== null) {
            return [$this->reader->planCity($planId), $orderReference];
        }

        return [$orderReference === null ? null : $this->reader->orderReferenceCity($orderReference), $orderReference];
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
