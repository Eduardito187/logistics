<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió una métrica del TABLERO del día (torre de control).
 *
 * Viaja por los canales del tema `operations` que cubren el hecho (`operations.all` para la oficina y, cuando
 * la ciudad se resuelve barato, `operations.city.<departamento>` para el planificador de esa ciudad) con el
 * nombre estable `dashboard.updated`. Es solo el AVISO de «recalculá el tablero»: la carga lleva la fecha del
 * plan afectado (`plan_date`, `Y-m-d`).
 *
 * El OTRO evento del tema `operations`, `alerts.updated`, lo emite telemetría: este evento no lo toca.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`). Un solo intento, best-effort. Con
 * `BROADCAST_CONNECTION=null|log` el trabajo que lo emite no hace nada. Carga primitiva.
 */
final class DashboardUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'dashboard.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<string> $channels nombres de canal SIN el prefijo `private-` (los arma el shared)
     */
    public function __construct(
        public readonly array $channels,
        public readonly string $planDate,
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(static fn (string $channel): PrivateChannel => new PrivateChannel($channel), $this->channels);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{plan_date: string}
     */
    public function broadcastWith(): array
    {
        return ['plan_date' => $this->planDate];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
