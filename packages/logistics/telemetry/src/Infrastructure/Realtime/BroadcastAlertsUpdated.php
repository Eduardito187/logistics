<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Telemetry\Presentation\Broadcasting\AlertsUpdated;
use Logistics\Telemetry\Presentation\Broadcasting\LiveBroadcastQueue;
use Throwable;

/**
 * Emite `alerts.updated` para una alerta que cambió, fuera del request (cola del fan-out en vivo / avisos).
 *
 * Recibe solo el id de la alerta (primitivo) y resuelve ACÁ, al ejecutar, la ciudad del camión de la alerta
 * ({@see AlertCityReader}). Así la señal alcanza también al planificador de esa ciudad, aunque el trabajo haya
 * esperado la ventana anti-ráfaga.
 *
 *  - Un solo intento: es best-effort; un error queda en el log y no se reintenta.
 *  - Sin transporte de tiempo real no hace nada (ni consulta ni emite).
 *  - Si no se puede resolver la ciudad, emite solo por `operations.all`.
 */
final class BroadcastAlertsUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $alertId,
    ) {
        $this->onQueue(LiveBroadcastQueue::name());
    }

    public function handle(AlertCityReader $reader): void
    {
        if (!AlertsRealtime::enabled()) {
            return;
        }

        try {
            AlertsUpdated::dispatch($this->alertId, $reader->departmentOf($this->alertId));
        } catch (Throwable $e) {
            report($e);
        }
    }
}
