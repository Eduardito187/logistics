<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Support\Facades\DB;
use Logistics\Telemetry\Domain\Contract\LivePositionBroadcaster;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Presentation\Broadcasting\VehiclePositionUpdated;
use Throwable;

/**
 * Adaptador del fan-out en vivo: resuelve la placa (lote), refresca el estado caliente en Valkey y
 * difunde la posición por WebSockets con COALESCING/throttle.
 *
 * Secuencia por posición nueva:
 *  1. Resolver la placa legible (cross-context read-only, memoizado).
 *  2. Siempre refrescar el hot-state en Valkey (que un espectador recién conectado tenga el último dato).
 *  3. Solo si el throttle lo permite (~1 cada N s por vehículo), emitir el broadcast — así una ráfaga de
 *     pings no se traduce en una tormenta de eventos a los navegadores.
 *
 * ADITIVO y a prueba de fallos: toda la operación va en try/catch; si el fan-out falla (Valkey caído,
 * broadcaster sin configurar), la ingesta no se entera. El broadcast se difiere a `afterCommit` para no
 * emitir dentro de la transacción de la ingesta (si no hay transacción abierta, se emite de inmediato).
 */
final readonly class BroadcastingLivePositionBroadcaster implements LivePositionBroadcaster
{
    public function __construct(
        private LiveVehicleState $state,
        private FleetPlateResolver $plates,
    ) {}

    public function publish(VehiclePing $ping, bool $moving): void
    {
        try {
            $vehicleId = $ping->vehicleId();
            $plate = $this->plates->label($vehicleId);
            $status = $this->statusOf($ping, $moving);

            $payload = [
                'vehicle_id' => $vehicleId,
                'plate' => $plate,
                'lat' => $ping->point()->lat,
                'lng' => $ping->point()->lng,
                'status' => $status,
                'occurred_at' => $ping->recordedAt()->format(DATE_ATOM),
            ];

            // Hot-state siempre fresco, aunque esta posición no se difunda por throttle.
            $this->state->remember($vehicleId, $payload);

            if (!$this->state->shouldEmit($vehicleId)) {
                return;
            }

            $event = new VehiclePositionUpdated(
                $vehicleId,
                $plate,
                $payload['lat'],
                $payload['lng'],
                $status,
                $payload['occurred_at'],
            );

            // Difundir tras el commit de la ingesta (si hay transacción); fuera de ella, de inmediato.
            DB::afterCommit(static function () use ($event): void {
                broadcast($event);
            });
        } catch (Throwable) {
            // El fan-out es best-effort: nunca rompe ni altera la semántica de la ingesta.
        }
    }

    /**
     * Estado legible para el espectador a partir de movimiento + encendido: en marcha, ralentí o detenido.
     */
    private function statusOf(VehiclePing $ping, bool $moving): string
    {
        if ($moving) {
            return 'moving';
        }

        return $ping->ignition() === true ? 'idle' : 'stopped';
    }
}
