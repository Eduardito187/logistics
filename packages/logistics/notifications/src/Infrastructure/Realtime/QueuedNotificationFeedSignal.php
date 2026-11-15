<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Realtime;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Logistics\Notifications\Application\Contract\NotificationFeedSignal;
use Throwable;

/**
 * Adaptador de la señal de tiempo real: ENCOLA {@see BroadcastNotificationsUpdated}; no cuenta, no consulta
 * y no habla con el servidor de sockets en el request.
 *
 *  - Después del commit: los handlers corren dentro de la transacción del CommandBus; la señal se arma
 *    recién cuando el cambio quedó confirmado (si hay rollback, no se avisa nada).
 *  - Anti-ráfaga: muchos cambios seguidos se avisan una sola vez por ventana corta (`Cache::add` + el
 *    trabajo demorado esa misma ventana, para que al llegar el aviso la lista ya incluya la ráfaga). La
 *    ventana del log es global; la del estado de lectura es por usuario.
 *  - Nunca lanza: si encolar o el caché fallan, el caso de uso sigue y la app verá el cambio en su
 *    próximo pedido de la lista.
 *  - Sin transporte de tiempo real (`BROADCAST_CONNECTION=null|log`) no hace nada.
 */
final class QueuedNotificationFeedSignal implements NotificationFeedSignal
{
    /** Ventana anti-ráfaga por defecto, en segundos. */
    private const DEFAULT_DEBOUNCE_SECONDS = 3;

    private const FEED_KEY = 'notifications:realtime:feed';

    private const USER_KEY = 'notifications:realtime:user:';

    public function feedChanged(): void
    {
        $this->afterCommit(function (): void {
            $this->enqueueOnce(self::FEED_KEY, [], true);
        });
    }

    public function readStateChanged(string $userId): void
    {
        $userId = trim($userId);
        // El canal personal es por id entero de usuario; cualquier otra identidad no tiene canal.
        if (!ctype_digit($userId) || (int) $userId <= 0) {
            return;
        }

        $id = (int) $userId;

        $this->afterCommit(function () use ($id): void {
            $this->enqueueOnce(self::USER_KEY.$id, [$id], false);
        });
    }

    /**
     * Corre la emisión cuando la transacción en curso se confirma (o ya, si no hay ninguna abierta).
     *
     * @param callable(): void $emit
     */
    private function afterCommit(callable $emit): void
    {
        try {
            if (!RealtimeTransport::enabled()) {
                return;
            }

            DB::afterCommit(static function () use ($emit): void {
                // Se ejecuta al confirmar la transacción del caso de uso: un error acá no debe salir.
                try {
                    $emit();
                } catch (Throwable $e) {
                    report($e);
                }
            });
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Encola el trabajo de difusión a lo sumo una vez por ventana para esa clave.
     *
     * @param list<int> $userIds
     */
    private function enqueueOnce(string $key, array $userIds, bool $audience): void
    {
        $seconds = $this->debounceSeconds();

        if (!Cache::add($key, true, $seconds)) {
            return;
        }

        BroadcastNotificationsUpdated::dispatch($userIds, $audience)->delay($seconds);
    }

    private function debounceSeconds(): int
    {
        $seconds = config('logistics-notifications.realtime.debounce_seconds', self::DEFAULT_DEBOUNCE_SECONDS);

        return is_numeric($seconds) && (int) $seconds > 0 ? (int) $seconds : self::DEFAULT_DEBOUNCE_SECONDS;
    }
}
