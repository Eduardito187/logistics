<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Support\Facades\DB;
use Logistics\Telemetry\Domain\Contract\LivePositionBroadcaster;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Presentation\Broadcasting\FleetPositionChanged;
use Logistics\Telemetry\Presentation\Broadcasting\VehiclePositionUpdated;
use Throwable;

/**
 * Adaptador del fan-out en vivo: resuelve la placa (lote), refresca el estado caliente en Valkey y
 * difunde la posición por WebSockets con COALESCING/throttle.
 *
 * Secuencia por posición nueva:
 *  1. Resolver la placa legible (cross-context read-only, en caché corta).
 *  2. Siempre refrescar el hot-state en Valkey (que un espectador recién conectado tenga el último dato).
 *  3. Solo si el throttle lo permite (~1 cada N s por vehículo), emitir el broadcast — así una ráfaga de
 *     pings no se traduce en una tormenta de eventos a los navegadores.
 *
 * Son dos eventos, cada uno con su throttle: `vehicle.position` (admin, carga reducida) y `fleet.position`
 * (app, el objeto completo de `fleet/live`).
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
        private LiveFleetViewFactory $fleetViews,
        private TrailingFleetEmitter $trailing,
    ) {}

    public function publish(VehiclePing $ping, bool $moving): void
    {
        try {
            $vehicleId = $ping->vehicleId();
            $plate = $this->plates->label($vehicleId);
            // Nodo base del vehículo (misma lectura en lote que la placa): acota el canal por nodo.
            $homeBaseNode = $this->plates->homeBaseNode($vehicleId);
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

            // Evento de la app (`fleet.position`): lleva su propio throttle y su propio try/catch.
            $this->publishFleetPosition($ping, $moving);

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
                $homeBaseNode,
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
     * Difunde `fleet.position` (canales privados `fleet.all` / `fleet.city.<ciudad>`) con el objeto completo
     * de `fleet/live`, como mucho una vez por vehículo por ventana (`live.fleet_throttle_seconds`).
     *
     * El objeto se arma SOLO si el throttle deja pasar y con datos de referencia en caché corta. Una lectura
     * descartada no se pierde: queda pendiente (forma primitiva) y un único trabajo por vehículo la emite al
     * cerrar la ventana si no salió otra más nueva (ver TrailingFleetEmitter), para que la app no se quede
     * con una posición vieja cuando el camión se detiene. Best-effort: un fallo acá no afecta ni a la ingesta
     * ni al evento del admin.
     */
    private function publishFleetPosition(VehiclePing $ping, bool $moving): void
    {
        try {
            $vehicleId = $ping->vehicleId();

            if (!$this->state->shouldEmitFleet($vehicleId)) {
                // Descartada por el throttle: queda pendiente y sale al cerrar la ventana si no llega otra.
                $this->trailing->defer($ping, $moving);

                return;
            }

            // Un camión dado de baja en flota ya no es parte de la flota en vivo de la app.
            if ($this->fleetViews->isRetired($vehicleId)) {
                return;
            }

            $event = new FleetPositionChanged($this->fleetViews->fromPing($ping, $moving));
            $this->trailing->noteEmitted($vehicleId, $ping->recordedAt());

            DB::afterCommit(static function () use ($event): void {
                broadcast($event);
            });
        } catch (Throwable) {
            // Sin tiempo real para la app en este ping: `fleet/live` sigue siendo la fuente de verdad.
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
