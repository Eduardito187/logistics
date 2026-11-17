<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Execution\Presentation\Broadcasting\LoadingUpdated;
use Throwable;

/**
 * Emite `loading.updated` para los camiones cuya carga cambió, fuera del request (cola `notifications`).
 *
 * Recibe solo lo que traía el hecho del outbox (camiones y envío, todo primitivo) y resuelve ACÁ, al
 * ejecutar, los canales del tema `loading` que cubren a cada camión ({@see LoadingSignalReader}: ciudad del
 * andén base y chofer vigente del día). Así la señal apunta a la tripulación/ciudad vigente cuando sale,
 * aunque el trabajo haya esperado la ventana anti-ráfaga.
 *
 *  - Un hecho que mueve un envío entre dos camiones (reasignación) avisa a AMBOS: su hoja de carga cambió.
 *  - Un solo intento: es best-effort; un error queda en el log y no se reintenta.
 *  - Sin transporte de tiempo real no hace nada.
 */
final class BroadcastLoadingUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 30;

    /**
     * @param list<string> $vehicleIds camiones cuya carga cambió (al menos uno)
     */
    public function __construct(
        public readonly array $vehicleIds,
        public readonly ?string $shipmentId = null,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(LoadingSignalReader $reader): void
    {
        if (!LoadingRealtime::enabled()) {
            return;
        }

        try {
            foreach ($this->vehicleIds as $vehicleId) {
                $channels = $reader->channelsForVehicle($vehicleId);
                if ($channels !== []) {
                    LoadingUpdated::dispatch($channels, $vehicleId, $this->shipmentId);
                }
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
