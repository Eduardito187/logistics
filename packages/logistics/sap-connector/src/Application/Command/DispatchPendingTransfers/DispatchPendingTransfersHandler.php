<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\DispatchPendingTransfers;

use DateInterval;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Throwable;

/**
 * Worker de reconciliación: empuja a SAP los traspasos despachables que elija el comando (Failed con el
 * backoff vencido y, opcionalmente, Pending estancados), en un lote acotado. Por cada uno, si SAP
 * responde bien lo deja Sent; si falla lo deja Failed con el motivo (visible en el panel de
 * reconciliación) y se reintenta en una corrida posterior. Un fallo aislado no corta el lote.
 *
 * Corre fuera del request de usuario y fuera de cualquier transacción larga: lo invocan el job de la
 * cola `sap` y el comando del scheduler llamando al handler directo (cada save es su propia escritura),
 * así el HTTP a SAP nunca retiene una transacción abierta. El empuje a SAP pasa por el puerto SapClient.
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
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof DispatchPendingTransfers) {
            throw SapConnectorException::because('DispatchPendingTransfersHandler solo maneja DispatchPendingTransfers');
        }

        $this->lastSent = 0;
        $this->lastFailed = 0;

        $now = $this->clock->now();
        $pendingBefore = $command->includePending
            ? $now->sub(new DateInterval('PT'.max(0, $command->pendingOlderThanMinutes).'M'))
            : null;
        $failedBefore = $now->sub(new DateInterval('PT'.max(0, $command->failedOlderThanMinutes).'M'));

        // Presupuesto de tiempo (opcional): el job de cola corta antes de su timeout; lo que quede lo
        // retoma la próxima corrida (el estado vive en la base, no en el lote).
        $deadline = $command->timeBudgetSeconds > 0 ? $now->getTimestamp() + $command->timeBudgetSeconds : null;

        foreach ($this->transfers->dispatchable($pendingBefore, $failedBefore, max(1, $command->limit)) as $transfer) {
            if ($deadline !== null && $this->clock->now()->getTimestamp() >= $deadline) {
                break;
            }

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
