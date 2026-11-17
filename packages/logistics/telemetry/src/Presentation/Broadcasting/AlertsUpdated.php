<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;

/**
 * Evento de BROADCAST para la app: cambió la lista de alertas de telemetría (se levantó una alerta nueva o se
 * reconoció/cerró una), lo que altera `GET /api/v1/app/telemetry/alerts`. Viaja con el nombre estable
 * `alerts.updated` y la app lo toma como «volvé a pedir la lista de alertas»: no trae la alerta completa, solo
 * su id para que la app pueda apuntar el refresco si quiere.
 *
 * Carga: `{alert_id: <uuid>|null}`. Un `alert_id` concreto es la alerta que cambió; `null` significa que
 * cambió la lista en general (sin una alerta puntual).
 *
 * Canales (privados; tema `operations` del contrato compartido `RealtimeChannels`):
 *  - `private-operations.all`             : oficina (admin/supervisor), ve todas las ciudades.
 *  - `private-operations.city.<ciudad>`   : planificador de la ciudad del camión de la alerta (departamento de
 *                                           su nodo base). Solo se suma si la ciudad se pudo resolver.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`): nada de I/O externo en el request.
 * Un solo intento a propósito: si el servidor de sockets no está, el aviso se pierde y la app se corrige sola
 * en su próximo refresco de la lista. Con `BROADCAST_CONNECTION=null|log` no emite.
 */
final class AlertsUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const string NAME = 'alerts.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param string|null $alertId id de la alerta que cambió, o null si cambió la lista en general
     * @param string|null $city    departamento del camión de la alerta, o null si no se pudo resolver
     */
    public function __construct(
        public readonly ?string $alertId,
        public readonly ?string $city = null,
    ) {}

    /**
     * Canales del tema `operations` según la ciudad de la alerta (`all` siempre; la ciudad si se conoce).
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return array_map(
            static fn (string $name): PrivateChannel => new PrivateChannel($name),
            RealtimeChannels::forEntity(RealtimeChannels::TOPIC_OPERATIONS, ['city' => $this->city]),
        );
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * Solo el id de la alerta: la app no necesita más para refrescar la lista.
     *
     * @return array{alert_id: string|null}
     */
    public function broadcastWith(): array
    {
        return ['alert_id' => $this->alertId];
    }

    /**
     * Cola del trabajo de difusión: la del fan-out en vivo o, si no hay, la de los avisos (siempre con worker).
     */
    public function broadcastQueue(): string
    {
        return LiveBroadcastQueue::name();
    }
}
