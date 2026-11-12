<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfersHandler;

/**
 * Worker de reconciliación del scheduler (cada minuto): reempuja a SAP SOLO los traspasos Failed cuyo
 * backoff ya venció (`transfers.retry_backoff_minutes`), en lotes chicos (`transfers.batch`). Nunca toma
 * los Pending: los traspasos nuevos los empuja únicamente el reactor del outbox (SapMessageDispatcher),
 * así no hay doble envío.
 *
 * Llama al handler directo, fuera de cualquier transacción: el HTTP a SAP no retiene la base. Con
 * `--include-stale-pending` también rescata Pending estancados (uso manual/operativo).
 */
final class DispatchStockTransfersCommand extends Command
{
    protected $signature = 'logistics:sap:dispatch-transfers
        {--include-stale-pending : Also push pending transfers untouched for longer than the stale threshold}';

    protected $description = 'Retry failed stock transfers to SAP whose backoff elapsed (reconciliation worker)';

    public function handle(DispatchPendingTransfersHandler $handler, Config $config): int
    {
        $handler->handle(new DispatchPendingTransfers(
            includePending: (bool) $this->option('include-stale-pending'),
            pendingOlderThanMinutes: (int) $config->get('logistics-sap-connector.transfers.stale_pending_minutes', 10),
            failedOlderThanMinutes: (int) $config->get('logistics-sap-connector.transfers.retry_backoff_minutes', 5),
            limit: (int) $config->get('logistics-sap-connector.transfers.batch', 50),
        ));

        $this->info(sprintf(
            'Stock transfer dispatch finished: %d sent, %d failed.',
            $handler->lastSent(),
            $handler->lastFailed(),
        ));

        return self::SUCCESS;
    }
}
