<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Events\InboxUpdated;
use App\Support\Staff\StaffInbox;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Calcula el no-leído de la bandeja interna de un grupo de personas y emite `inbox.updated` a cada una.
 *
 * Corre en la cola `notifications`, nunca en el request: un aviso a miles de destinatarios no cuenta nada
 * de forma síncrona. El no-leído se calcula ACÁ (al ejecutar), no al encolar, así el número que llega al
 * teléfono es el vigente aunque el trabajo haya esperado. Una sola consulta agrupada por lote de usuarios.
 *
 * Con `$office = true` ignora `$userIds` y avisa a toda la oficina (admin y supervisor): es el enganche
 * para las fuentes de la bandeja que no tienen destinatario propio (alertas de flota, documentos por vencer).
 *
 * Tolerante: sin transporte de tiempo real (`BROADCAST_CONNECTION=null`) no hace nada.
 */
final class BroadcastInboxUpdated implements ShouldQueue
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
        public readonly array $userIds,
        public readonly bool $office = false,
    ) {
        $this->onQueue((string) config('observability.metrics.queues.notifications', 'notifications'));
    }

    public function handle(StaffInbox $inbox): void
    {
        if (!StaffInbox::realtimeEnabled()) {
            return;
        }

        $userIds = $this->office ? $inbox->officeUserIds() : $this->userIds;

        foreach ($inbox->unreadFor($userIds) as $userId => $unread) {
            InboxUpdated::dispatch($userId, $unread);
        }
    }
}
