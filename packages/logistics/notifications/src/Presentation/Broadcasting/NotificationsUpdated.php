<?php

declare(strict_types=1);

namespace Logistics\Notifications\Presentation\Broadcasting;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Evento de BROADCAST: cambió lo que una persona ve en la pantalla de notificaciones de la app
 * (`GET /app/notifications` y `/app/notifications/all`).
 *
 * Viaja por el canal privado de cada usuario (`private-user.{id}`, autorizado en routes/channels.php solo
 * al propio usuario) con el nombre estable `notifications.updated`. Es solo la SEÑAL para que la app vuelva
 * a pedir la lista: el contenido de las notificaciones nunca viaja por el socket.
 *
 * Carga: `{"scope": "feed"}` si cambió el log (nueva, enviada, escalada, suprimida) o `{"scope": "user"}`
 * si cambió el estado de lectura del propio usuario. Nunca va vacía: un arreglo vacío se serializa como
 * `[]` y no como `{}`, y el cliente espera un objeto.
 *
 * Un mismo evento puede llevar varios canales (un lote de usuarios): el transporte Pusher los publica en
 * una sola llamada, así avisar a la audiencia no encola un trabajo por persona.
 *
 * Se difunde por la COLA (`ShouldBroadcast`, nunca `ShouldBroadcastNow`) y con un solo intento: si el
 * servidor de sockets no está, el aviso se pierde sin reintentos ni ruido.
 */
final class NotificationsUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;

    /** Nombre del evento en el cliente (desacoplado del FQCN). */
    public const NAME = 'notifications.updated';

    /** Cambió el log de notificaciones. */
    public const SCOPE_FEED = 'feed';

    /** Cambió el estado de lectura del propio usuario. */
    public const SCOPE_USER = 'user';

    /** Tope de canales por evento (límite de canales por publicación del protocolo Pusher). */
    public const MAX_CHANNELS = 100;

    /** Intentos del trabajo de difusión: uno, es best-effort. */
    public int $tries = 1;

    /** Tope en segundos del trabajo de difusión. */
    public int $timeout = 15;

    /**
     * @param list<int> $userIds Destinatarios (ids enteros de usuario); a lo sumo MAX_CHANNELS.
     */
    public function __construct(
        public readonly array $userIds,
        public readonly string $scope = self::SCOPE_FEED,
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
        $channels = [];
        foreach (array_slice($this->userIds, 0, self::MAX_CHANNELS) as $userId) {
            if ($userId > 0) {
                $channels[] = new PrivateChannel(self::channelName($userId));
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return self::NAME;
    }

    /**
     * @return array{scope: string}
     */
    public function broadcastWith(): array
    {
        return ['scope' => $this->scope === self::SCOPE_USER ? self::SCOPE_USER : self::SCOPE_FEED];
    }

    /**
     * Cola del trabajo de difusión: la misma de los avisos (su nombre real sale de la config).
     */
    public function broadcastQueue(): string
    {
        return (string) config('observability.metrics.queues.notifications', 'notifications');
    }
}
