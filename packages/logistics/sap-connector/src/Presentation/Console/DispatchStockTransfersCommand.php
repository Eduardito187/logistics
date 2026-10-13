<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfersHandler;

/**
 * Worker de reconciliación: empuja a SAP los traspasos pendientes. Lo corre el worker/scheduler
 * (p. ej. cada minuto) o se invoca a mano. No hace I/O por sí mismo: delega en el handler, que usa el
 * puerto SapClient. Imprime el resumen de la corrida para poder monitorearlo.
 */
final class DispatchStockTransfersCommand extends Command
{
    protected $signature = 'logistics:sap:dispatch-transfers';

    protected $description = 'Dispatch pending stock transfers to SAP (reconciliation worker)';

    public function handle(DispatchPendingTransfersHandler $handler): int
    {
        $handler->handle(new DispatchPendingTransfers);

        $this->info(sprintf(
            'Stock transfer dispatch finished: %d sent, %d failed.',
            $handler->lastSent(),
            $handler->lastFailed(),
        ));

        return self::SUCCESS;
    }
}
