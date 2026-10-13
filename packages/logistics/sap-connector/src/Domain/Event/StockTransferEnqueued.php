<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se encoló un traspaso de stock al recibir en un nodo. Es el hecho que alimenta el outbox hacia SAP:
 * el despacho real es asíncrono y el escaneo nunca lo espera (ADR 002).
 *
 * `receptionEventId` es la clave de idempotencia de origen (el event_id de la recepción): garantiza
 * que una misma recepción no genere dos traspasos, aun si el evento se reprocesa.
 */
final readonly class StockTransferEnqueued extends BaseDomainEvent
{
    public function __construct(
        string $stockTransferId,
        public string $receptionEventId,
        public string $shipmentRef,
        public string $material,
        public string $center,
        public string $warehouse,
        public int $quantity,
    ) {
        parent::__construct($stockTransferId);
    }

    public function eventName(): string
    {
        return 'sap_connector.stock_transfer_enqueued';
    }

    public function payload(): array
    {
        return [
            'stock_transfer_id' => $this->aggregateId,
            'reception_event_id' => $this->receptionEventId,
            'shipment_ref' => $this->shipmentRef,
            'material' => $this->material,
            'center' => $this->center,
            'warehouse' => $this->warehouse,
            'quantity' => $this->quantity,
        ];
    }
}
