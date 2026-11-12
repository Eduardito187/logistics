<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrders;
use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Job de la cola `sap`: lee los pedidos pendientes de SAP y los traduce al modelo propio (ImportSapOrders),
 * fuera del request que lo pidió. El resultado queda en el log (cantidad de pedidos traducidos); quien
 * orqueste la recepción en shipments consumirá los DTOs del handler cuando exista ese puente.
 *
 * NUNCA lanza: un SAP caído o un pedido intraducible queda en el log y no afecta a nadie.
 */
final class ImportSapOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $timeout = 100;

    /** Ventana de unicidad (segundos): pedidos repetidos se colapsan en una sola lectura. */
    public int $uniqueFor = 300;

    public function handle(ImportSapOrdersHandler $handler, LoggerInterface $logger): void
    {
        try {
            $handler->handle(new ImportSapOrders);

            $logger->info('SAP orders import finished', ['orders' => count($handler->importedOrders())]);
        } catch (Throwable $error) {
            $logger->warning('SAP orders import failed', ['error' => $error->getMessage()]);
        }
    }
}
