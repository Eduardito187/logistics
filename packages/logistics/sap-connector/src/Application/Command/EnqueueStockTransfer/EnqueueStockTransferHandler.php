<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\EnqueueStockTransfer;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\StockTransferId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Encola un traspaso de stock a SAP desde una recepción en nodo. El escaneo NUNCA espera a SAP: este
 * handler solo persiste el traspaso Pending; el empuje real lo hace el worker de despacho (ADR 002).
 *
 * Idempotente por eventId: si ya existe un traspaso para ese event_id de recepción, no crea otro y deja
 * el id del existente disponible en lastTransferId(); tampoco vuelve a grabar eventos.
 *
 * @implements CommandHandler<EnqueueStockTransfer>
 */
final class EnqueueStockTransferHandler implements CommandHandler
{
    private ?StockTransferId $lastTransferId = null;

    public function __construct(
        private readonly StockTransferRepository $transfers,
        private readonly MaterialMappingRepository $mappings,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof EnqueueStockTransfer) {
            throw SapConnectorException::because('EnqueueStockTransferHandler solo maneja EnqueueStockTransfer');
        }

        // Idempotencia: la misma recepción (event_id) encola un solo traspaso.
        $existing = $this->transfers->findByEventId($command->eventId);
        if ($existing !== null) {
            $this->lastTransferId = $existing->id();

            return;
        }

        // Traducción anti-corrupción: sku propio → coordenada SAP (ADR 002).
        $mapping = $this->mappings->findBySku($command->sku);
        if ($mapping === null) {
            throw SapConnectorException::because("no hay mapeo SAP para el sku {$command->sku}");
        }

        $transfer = StockTransfer::fromReception(
            $this->transfers->nextIdentity(),
            $command->eventId,
            $command->shipmentRef,
            $mapping->material(),
            $mapping->center(),
            $mapping->warehouse(),
            $command->quantity,
        );

        $this->transfers->save($transfer);
        $this->lastTransferId = $transfer->id();

        $this->recordEvents->record($transfer->releaseEvents(), 'sap.stock_transfer');
    }

    /**
     * Id del traspaso encolado (o del existente por idempotencia) tras el último handle().
     */
    public function lastTransferId(): StockTransferId
    {
        if ($this->lastTransferId === null) {
            throw SapConnectorException::because('todavía no se encoló ningún traspaso en este handler');
        }

        return $this->lastTransferId;
    }
}
