<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Telemetry\Infrastructure\LiveState\TrailingFleetEmitter;
use Logistics\Telemetry\Presentation\Broadcasting\LiveBroadcastQueue;

/**
 * Emisión final de `fleet.position` de un vehículo al cerrar la ventana del throttle (ver
 * TrailingFleetEmitter). Se encola con espera = la ventana y hay como mucho uno en espera por vehículo.
 *
 * - Asíncrono: corre en el worker de la cola del fan-out en vivo, nunca en la ingesta.
 * - Idempotente: solo emite si la lectura pendiente es más nueva que la última difundida; una copia de más
 *   no duplica nada.
 * - Un solo intento: es best-effort; lo que no salió lo corrige la próxima posición o `fleet/live`.
 *
 * Lleva solo primitivos: el id del vehículo y el instante (epoch) antes del cual no debería correr. Si corre
 * antes (cola `sync`, que ignora la espera), no se reprograma.
 */
final class EmitTrailingFleetPositionJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /** Tope en segundos del trabajo. */
    public int $timeout = 15;

    public function __construct(
        private readonly string $vehicleId,
        private readonly int $notBefore,
    ) {
        $this->onQueue(LiveBroadcastQueue::name());
    }

    public function handle(TrailingFleetEmitter $emitter): void
    {
        $emitter->flush($this->vehicleId, time() >= $this->notBefore);
    }

    public function vehicleId(): string
    {
        return $this->vehicleId;
    }
}
