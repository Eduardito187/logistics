<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * SAP confirmó un traspaso de stock. Cierra el ciclo del traspaso y permite sacarlo del panel de
 * reconciliación.
 */
final readonly class StockTransferAcknowledged extends BaseDomainEvent
{
    public function __construct(
        string $stockTransferId,
        public string $receptionEventId,
    ) {
        parent::__construct($stockTransferId);
    }

    public function eventName(): string
    {
        return 'sap_connector.stock_transfer_acknowledged';
    }

    public function payload(): array
    {
        return [
            'stock_transfer_id' => $this->aggregateId,
            'reception_event_id' => $this->receptionEventId,
        ];
    }
}
