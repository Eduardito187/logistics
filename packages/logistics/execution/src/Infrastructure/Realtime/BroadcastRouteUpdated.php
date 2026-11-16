<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Logistics\Execution\Application\Contract\LiveRouteStateReader;
use Logistics\Execution\Presentation\Broadcasting\RouteUpdated;
use Throwable;

/**
 * Emite `route.updated` a la tripulación de la ruta que cambió, fuera del request (cola `notifications`).
 *
 * Recibe solo lo que traía el hecho del outbox (ruta, camiones, envío, día: todo primitivo) y resuelve ACÁ,
 * al ejecutar, la ruta vigente, su camión y los usuarios de la tripulación ({@see RouteSignalReader}). Así
 * la señal apunta a lo que hay en la base cuando sale, aunque el trabajo haya esperado.
 *
 *  - Sin nadie vinculado a la ruta no emite nada.
 *  - Un mismo cambio suele producir varios hechos con claves distintas (el del envío y el de su parada):
 *    al resolver todos a la misma ruta, solo el primero de la ventana emite. No se pierde nada: un trabajo
 *    que llega dentro de la ventana viene de un hecho anterior a la señal que ya salió, y la app pide la
 *    ruta después de esa señal.
 *  - Un solo intento: es best-effort; un error queda en el log y no se reintenta.
 *  - Sin transporte de tiempo real no hace nada.
 *
 * @phpstan-import-type LiveRouteState from LiveRouteStateReader
 */
final class BroadcastRouteUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    private const EMITTED_KEY = 'execution:realtime:route-emitted:';

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param list<string> $vehicleIds
     */
    public function __construct(
        public readonly ?string $routeId = null,
        public readonly array $vehicleIds = [],
        public readonly ?string $shipmentId = null,
        public readonly ?string $date = null,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(RouteSignalReader $reader, LiveRouteStateReader $liveState): void
    {
        if (!RouteRealtime::enabled()) {
            return;
        }

        try {
            foreach ($reader->targets($this->routeId, $this->vehicleIds, $this->shipmentId, $this->date) as $target) {
                $userIds = $reader->userIds($target);

                if ($userIds !== [] && $this->firstInWindow($target['route_id'], $target['vehicle_id'])) {
                    $this->emit($userIds, $target['route_id'], $target['vehicle_id'], $liveState);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Emite la señal, enriquecida con el estado en vivo del motor único de ETA (ETA viva por parada y "fuera
     * de ruta") cuando está disponible. `rerouted` = `off_route`: estar fuera de ruta implica que la ETA se
     * recalculó desde la posición actual hacia la siguiente parada. Sin motor de ETA, viaja la carga histórica.
     *
     * @param list<int> $userIds
     */
    private function emit(array $userIds, ?string $routeId, string $vehicleId, LiveRouteStateReader $liveState): void
    {
        $live = $liveState->forVehicle($vehicleId);
        // Solo se enriquece cuando el motor de ETA aportó algo útil (ETA viva o estado "fuera de ruta"); si
        // no hay dato en vivo viaja la carga histórica de dos campos, sin romper compatibilidad.
        $offRoute = null;
        $stops = [];
        if ($live !== null && ($live['etas'] !== [] || $live['off_route'])) {
            $offRoute = $live['off_route'];
            $stops = $this->stopEtas($live);
        }
        // `rerouted` = `off_route`: estar fuera de ruta implica que la ETA se recalculó desde la posición
        // actual hacia la siguiente parada (motor único).
        $rerouted = $offRoute;

        foreach (array_chunk($userIds, RouteUpdated::MAX_CHANNELS) as $chunk) {
            RouteUpdated::dispatch($chunk, $routeId, $vehicleId, $offRoute, $rerouted, $stops);
        }
    }

    /**
     * ETA viva por parada pendiente, en la forma del payload. Vacío si no hay motor de ETA.
     *
     * @param  LiveRouteState|null                              $live
     * @return list<array{shipment_id: string, eta_at: string}>
     */
    private function stopEtas(?array $live): array
    {
        if ($live === null) {
            return [];
        }

        $stops = [];
        foreach ($live['etas'] as $shipmentId => $etaAt) {
            $stops[] = ['shipment_id' => $shipmentId, 'eta_at' => $etaAt];
        }

        return $stops;
    }

    private function firstInWindow(?string $routeId, string $vehicleId): bool
    {
        return Cache::add(
            self::EMITTED_KEY.$vehicleId.':'.($routeId ?? 'none'),
            true,
            RouteRealtime::debounceSeconds(),
        );
    }
}
