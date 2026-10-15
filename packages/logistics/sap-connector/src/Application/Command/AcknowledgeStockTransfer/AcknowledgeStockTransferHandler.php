<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\AcknowledgeStockTransfer;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\ValueObject\TransferStatus;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la confirmación de SAP sobre un traspaso ya empujado (cierra el ciclo y lo saca del panel de
 * reconciliación). Se resuelve por eventId (clave de idempotencia). Graba el hecho de confirmación en
 * el outbox.
 *
 * Idempotente: si el traspaso ya está confirmado, no hace nada (no es un error reconfirmar).
 *
 * @implements CommandHandler<AcknowledgeStockTransfer>
 */
final class AcknowledgeStockTransferHandler implements CommandHandler
{
    public function __construct(
        private readonly StockTransferRepository $transfers,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof AcknowledgeStockTransfer) {
            throw SapConnectorException::because('AcknowledgeStockTransferHandler solo maneja AcknowledgeStockTransfer');
        }

        $transfer = $this->transfers->findByEventId($command->eventId);
        if ($transfer === null) {
            throw SapConnectorException::because("no existe un traspaso para el event_id {$command->eventId}");
        }

        // Idempotencia: reconfirmar un traspaso ya confirmado es un no-op.
        if ($transfer->status() === TransferStatus::Acknowledged) {
            return;
        }

        $transfer->acknowledge();

        $this->transfers->save($transfer);
        $this->recordEvents->record($transfer->releaseEvents(), 'sap.stock_transfer');
    }
}
