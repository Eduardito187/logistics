<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DispatchPendingTransfers;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Throwable;

/**
 * Worker de despacho: empuja a SAP los traspasos pendientes (Pending y Failed). Por cada uno, si SAP
 * responde bien lo deja Sent; si falla lo deja Failed con el motivo, para que siga visible en el panel
 * de reconciliación y se reintente en la próxima corrida. Un fallo aislado no corta el lote.
 *
 * Corre fuera del request de usuario (relay/worker). El empuje a SAP es el ÚNICO I/O externo del
 * contexto y pasa por el puerto SapClient (ADR 002).
 *
 * @implements CommandHandler<DispatchPendingTransfers>
 */
final class DispatchPendingTransfersHandler implements CommandHandler
{
    private int $lastSent = 0;

    private int $lastFailed = 0;

    public function __construct(
        private readonly StockTransferRepository $transfers,
        private readonly SapClient $sap,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DispatchPendingTransfers) {
            throw SapConnectorException::because('DispatchPendingTransfersHandler solo maneja DispatchPendingTransfers');
        }

        $this->lastSent = 0;
        $this->lastFailed = 0;

        foreach ($this->transfers->pending() as $transfer) {
            $this->dispatchOne($transfer);
        }
    }

    private function dispatchOne(StockTransfer $transfer): void
    {
        // Regla dura (ADR 017, roadmap §6.4): el stock de un seller (consignación) NUNCA se postea a SAP
        // como material propio de Dismac. Se trata como movimiento logístico: queda Sent sin tocar SAP.
        if (!$transfer->kind()->postsOwnershipToSap()) {
            $transfer->markSent();
            $this->lastSent++;
            $this->transfers->save($transfer);
            $this->recordEvents->record($transfer->releaseEvents(), 'sap.stock_transfer');

            return;
        }

        try {
            $this->sap->pushStockTransfer($transfer);
            $transfer->markSent();
            $this->lastSent++;
        } catch (Throwable $exception) {
            $transfer->markFailed($exception->getMessage());
            $this->lastFailed++;
        }

        $this->transfers->save($transfer);
        $this->recordEvents->record($transfer->releaseEvents(), 'sap.stock_transfer');
    }

    public function lastSent(): int
    {
        return $this->lastSent;
    }

    public function lastFailed(): int
    {
        return $this->lastFailed;
    }
}
