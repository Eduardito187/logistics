<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió el estado de la corrida automática de un día
 * (`queued` → `processing` → `completed` | `failed`).
 *
 * La carga trae `plan_date`, `strategy`, `status` y, bajo `run`, el MISMO objeto que devuelve
 * `GET /api/v1/app/plans/auto` (schema AutoPlanRun), así la app pinta el avance sin volver a consultar.
 *
 * La corrida abarca el día completo y no una ciudad, así que viaja por `private-planning.all` y por el canal
 * de cada ciudad (`private-planning.city.<ciudad>`), con el nombre estable `planning.run.updated`.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento (best-effort).
 * Carga primitiva, nunca modelos.
 *
 * @phpstan-type RunPayload array{id: string, plan_date: string, strategy: string, status: string, requested_at: string, completed_at: string|null, trucks_planned: int, stops_planned: int, unassigned_count: int, failure_reason: string|null}
 */
final class PlanningRunUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'planning.run.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param RunPayload   $run    forma de lectura de la corrida (la de `AutoPlanRunView::toArray()`)
     * @param list<string> $cities códigos de departamento de las ciudades a avisar
     */
    public function __construct(
        public readonly array $run,
        public readonly array $cities = [],
    ) {}

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return PlanningChannels::for($this->cities);
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{plan_date: string, strategy: string, status: string, run: RunPayload}
     */
    public function broadcastWith(): array
    {
        return [
            'plan_date' => $this->run['plan_date'],
            'strategy' => $this->run['strategy'],
            'status' => $this->run['status'],
            'run' => $this->run,
        ];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
