<?php

declare(strict_types=1);

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió el no-leído de la bandeja interna (`/app/inbox`) de una persona.
 *
 * Viaja por el canal privado de ese usuario (`private-user.{id}`, autorizado en routes/channels.php solo al
 * propio usuario) con el nombre estable `inbox.updated` y la carga `{unread}`. Es solo el AVISO de que hay
 * algo nuevo: la app refresca el badge con `unread` y, si tiene la bandeja abierta, vuelve a pedirla. El
 * contenido del aviso nunca viaja por el socket.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`): nada de I/O externo en el request.
 * Un solo intento a propósito: si el servidor de sockets no está, el aviso se pierde sin reintentos ni ruido
 * (la bandeja y el push siguen siendo la fuente de verdad). Con `BROADCAST_CONNECTION=null|log` no emite.
 *
 * Carga primitiva (id entero + contador), nunca modelos.
 */
final class InboxUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'inbox.updated';

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    public function __construct(
        public readonly int $userId,
        public readonly int $unread,
    ) {}

    /**
     * Nombre del canal SIN el prefijo `private-` (el que se declara en routes/channels.php).
     */
    public static function channelName(int $userId): string
    {
        return 'user.'.$userId;
    }

    /**
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel(self::channelName($this->userId))];
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{unread: int}
     */
    public function broadcastWith(): array
    {
        return ['unread' => max(0, $this->unread)];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
