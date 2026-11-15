<?php

declare(strict_types=1);

namespace Logistics\Planning\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambiaron los planes de reparto de un día (se creó uno, se le sumaron, quitaron o
 * reordenaron paradas, se publicó, se recalculó su recorrido o se borró).
 *
 * Es solo la SEÑAL para que la lista y el detalle se vuelvan a pedir en los demás teléfonos: la carga es
 * `{plan_date, plan_id, deleted}` y el contenido del plan nunca viaja por el socket. `plan_id` va cuando el
 * cambio tocó un único plan; va null cuando en la misma operación cambiaron varios planes de ese día (mover
 * un pedido de un camión a otro, la corrida automática): ahí se refresca la lista entera. `deleted` va
 * SIEMPRE: true cuando ese `plan_id` se borró (la app lo saca de la lista y cierra su detalle, sin volver a
 * pedirlo), false en cualquier otro cambio.
 *
 * Viaja por `private-planning.all` y por `private-planning.city.<ciudad>` de cada ciudad a la que pertenece
 * el plan (la base de su camión y los departamentos de origen y destino de sus pedidos), con el nombre
 * estable `plans.updated`.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento: si el
 * servidor de sockets no está, la señal se pierde sin reintentos (la app sigue pudiendo refrescar a mano).
 * Carga primitiva, nunca modelos.
 */
final class PlansUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'plans.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param string       $planDate día de los planes (Y-m-d)
     * @param string|null  $planId   plan que cambió, o null si cambiaron varios de ese día
     * @param list<string> $cities   códigos de departamento de las ciudades a avisar
     * @param bool         $deleted  true si `planId` se borró (ya no existe: no hay detalle que volver a pedir)
     */
    public function __construct(
        public readonly string $planDate,
        public readonly ?string $planId,
        public readonly array $cities = [],
        public readonly bool $deleted = false,
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
     * @return array{plan_date: string, plan_id: string|null, deleted: bool}
     */
    public function broadcastWith(): array
    {
        return ['plan_date' => $this->planDate, 'plan_id' => $this->planId, 'deleted' => $this->deleted];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
