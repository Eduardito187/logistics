<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Realtime;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\Notifications\Presentation\Broadcasting\NotificationsUpdated;

/**
 * Emite `notifications.updated` a quien corresponda, fuera del request (cola `notifications`).
 *
 * Dos modos:
 *  - `$audience = true`: cambió el log; resuelve ACÁ la audiencia ({@see NotificationFeedAudience}) y emite
 *    un evento por lote de usuarios (cada evento lleva hasta {@see NotificationsUpdated::MAX_CHANNELS}
 *    canales), así el abanico es de pocos trabajos y no de uno por persona.
 *  - `$audience = false`: cambió el estado de lectura de las personas de `$userIds`; solo a ellas.
 *
 * Un solo intento: es best-effort. Sin transporte de tiempo real no hace nada.
 */
final class BroadcastNotificationsUpdated implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    /**
     * @param list<int> $userIds
     */
    public function __construct(
        public readonly array $userIds = [],
        public readonly bool $audience = false,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(NotificationFeedAudience $feedAudience): void
    {
        if (!RealtimeTransport::enabled()) {
            return;
        }

        $userIds = $this->audience ? $feedAudience->userIds() : $this->userIds;
        $userIds = array_values(array_unique(array_filter($userIds, static fn (int $id): bool => $id > 0)));
        $scope = $this->audience ? NotificationsUpdated::SCOPE_FEED : NotificationsUpdated::SCOPE_USER;

        foreach (array_chunk($userIds, NotificationsUpdated::MAX_CHANNELS) as $chunk) {
            NotificationsUpdated::dispatch($chunk, $scope);
        }
    }
}
