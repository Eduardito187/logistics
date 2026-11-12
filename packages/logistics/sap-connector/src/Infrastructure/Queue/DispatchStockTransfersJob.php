<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfers;
use Logistics\SapConnector\Application\Command\DispatchPendingTransfers\DispatchPendingTransfersHandler;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Job de la cola `sap`: corrida de reconciliación pedida a mano (endpoint de la puerta app o acción del
 * panel). Reintenta los Failed (sin esperar el backoff: lo pidió un operador) y los Pending ESTANCADOS
 * (más viejos que `transfers.stale_pending_minutes`), nunca los recién encolados, que son del reactor
 * del outbox. Lote acotado por `transfers.batch`.
 *
 * Llama al handler directo (no por el CommandBus transaccional): cada traspaso se guarda por separado y
 * el HTTP a SAP nunca retiene una transacción abierta. Único por cola: pedirlo diez veces seguidas no
 * encola diez corridas.
 */
final class DispatchStockTransfersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    /**
     * Debajo del retry_after de la conexión redis (120s): el lote corta por presupuesto a los 90s y cada
     * llamada a SAP tiene su propio timeout (10s + 3s de connect), así el job nunca se re-libera en curso.
     */
    public int $timeout = 110;

    /** Ventana de unicidad (segundos): mientras haya una corrida encolada, no se encola otra. */
    public int $uniqueFor = 600;

    public function handle(DispatchPendingTransfersHandler $handler, Config $config, LoggerInterface $logger): void
    {
        try {
            $handler->handle(new DispatchPendingTransfers(
                includePending: true,
                pendingOlderThanMinutes: (int) $config->get('logistics-sap-connector.transfers.stale_pending_minutes', 10),
                failedOlderThanMinutes: 0,
                limit: (int) $config->get('logistics-sap-connector.transfers.batch', 50),
                timeBudgetSeconds: 90,
            ));

            $logger->info('SAP stock transfer reconciliation finished', [
                'sent' => $handler->lastSent(),
                'failed' => $handler->lastFailed(),
            ]);
        } catch (Throwable $error) {
            $logger->warning('SAP stock transfer reconciliation crashed', ['error' => $error->getMessage()]);
        }
    }
}
