<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\SapConnector\Application\Notification\RequeueDueSapNotifications;

/**
 * Barrido de reintentos de avisos a SAP (scheduler, cada minuto): re-encola en la cola `sap` los avisos
 * Pending/Failed con intentos < máximo y backoff vencido, en un lote chico y fuera de transacción. No
 * habla con SAP: el envío lo hace el job. Agotados los intentos, el aviso queda Failed y se lo deja.
 */
final class RetrySapNotificationsCommand extends Command
{
    protected $signature = 'logistics:sap:retry-notifications';

    protected $description = 'Re-queue SAP notifications whose retry backoff elapsed (fire-and-forget)';

    public function handle(RequeueDueSapNotifications $requeue): int
    {
        $count = $requeue->run();

        $this->info(sprintf('SAP notifications re-queued: %d.', $count));

        return self::SUCCESS;
    }
}
