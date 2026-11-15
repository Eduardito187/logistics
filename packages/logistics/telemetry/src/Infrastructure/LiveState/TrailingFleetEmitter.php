<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Queue\EmitTrailingFleetPositionJob;
use Logistics\Telemetry\Presentation\Broadcasting\FleetPositionChanged;
use Throwable;

/**
 * EMISIÓN FINAL de `fleet.position` tras el throttle ("trailing emit").
 *
 * El throttle deja pasar una posición por vehículo por ventana y descarta el resto. Si el camión se detiene
 * y su última lectura cayó dentro de la ventana, la app se quedaría con una posición vieja. Para evitarlo:
 *
 *  1. `defer()`: la lectura descartada queda PENDIENTE en el estado caliente (forma primitiva; cada descarte
 *     pisa la anterior) y se programa UN trabajo con espera = la ventana. La reserva es atómica
 *     (`Cache::add`), así que hay como mucho un trabajo en espera por vehículo, sin tormenta de trabajos.
 *  2. `flush()`: al ejecutar, el trabajo emite la lectura pendiente solo si es MÁS NUEVA que la última
 *     difundida (si en el medio salió otra por el camino normal, no duplica). Respeta el throttle: si la
 *     ventana sigue tomada por una emisión reciente, se reprograma una ventana más.
 *
 * Best-effort de punta a punta (cada paso en try/catch): nunca rompe la ingesta ni al worker. Se apaga con
 * `logistics-telemetry.live.trailing_emit` = false.
 */
final readonly class TrailingFleetEmitter
{
    public function __construct(
        private LiveVehicleState $state,
        private LiveFleetViewFactory $views,
    ) {}

    /**
     * El throttle descartó esta lectura: queda pendiente y se asegura un trabajo que la emita al cerrar la
     * ventana. Barato a propósito (una escritura + una reserva): no arma el objeto ni lee datos de referencia.
     */
    public function defer(VehiclePing $ping, bool $moving): void
    {
        try {
            if (!$this->enabled()) {
                return;
            }

            $vehicleId = $ping->vehicleId();
            $this->state->rememberPendingFleet($vehicleId, $this->views->snapshot($ping, $moving));
            $this->schedule($vehicleId);
        } catch (Throwable) {
            // Sin emisión final para esta lectura: `fleet/live` sigue siendo la fuente de verdad.
        }
    }

    /**
     * Anota que se difundió un `fleet.position` con esa lectura. La marca solo avanza: una lectura atrasada
     * que se difunde no hace retroceder el "último emitido".
     */
    public function noteEmitted(string $vehicleId, DateTimeImmutable $recordedAt): void
    {
        try {
            $instant = $recordedAt->format('Y-m-d\TH:i:s.uP');
            if ($this->isNewer($instant, $this->state->lastFleetEmittedAt($vehicleId))) {
                $this->state->markFleetEmitted($vehicleId, $instant);
            }
        } catch (Throwable) {
            // Sin la marca, a lo sumo la emisión final repite una posición.
        }
    }

    /**
     * Emite la lectura pendiente del vehículo si todavía aporta algo. Devuelve true si difundió.
     *
     * `$mayReschedule` va en false cuando el trabajo corrió sin haber esperado la ventana (cola `sync`):
     * ahí no se reprograma, para no encadenar trabajos que se ejecutan al instante.
     */
    public function flush(string $vehicleId, bool $mayReschedule = true): bool
    {
        try {
            // Primero se libera la reserva: un descarte que llegue desde acá programa su propio trabajo.
            $this->state->releaseTrailingFleet($vehicleId);

            $pending = $this->state->pendingFleet($vehicleId);
            if ($pending === null || !$this->isNewer($pending['recorded_at'], $this->state->lastFleetEmittedAt($vehicleId))) {
                // Nada pendiente, o ya salió una lectura igual o más nueva por el camino normal.
                return false;
            }

            if ($this->views->isRetired($vehicleId)) {
                $this->state->forgetPendingFleet($vehicleId);

                return false;
            }

            if (!$this->state->shouldEmitFleet($vehicleId)) {
                // La ventana sigue tomada por una emisión reciente: se espera una ventana más.
                if ($mayReschedule) {
                    $this->schedule($vehicleId);
                }

                return false;
            }

            broadcast(new FleetPositionChanged($this->views->fromSnapshot($pending)));
            $this->state->markFleetEmitted($vehicleId, $pending['recorded_at']);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Programa el trabajo de emisión final del vehículo, si no hay ya uno en espera.
     */
    private function schedule(string $vehicleId): void
    {
        if (!$this->state->claimTrailingFleet($vehicleId)) {
            return;
        }

        try {
            $delay = $this->state->fleetWindowSeconds();
            EmitTrailingFleetPositionJob::dispatch($vehicleId, time() + $delay)->delay($delay);
        } catch (Throwable) {
            // No se pudo encolar: se suelta la reserva para que el próximo descarte lo intente de nuevo.
            $this->state->releaseTrailingFleet($vehicleId);
        }
    }

    /** ¿La lectura `$instant` es estrictamente posterior a `$reference`? Sin referencia, sí. */
    private function isNewer(string $instant, ?string $reference): bool
    {
        if ($reference === null) {
            return true;
        }

        return new DateTimeImmutable($instant) > new DateTimeImmutable($reference);
    }

    private function enabled(): bool
    {
        return (bool) config('logistics-telemetry.live.trailing_emit', true);
    }
}
