<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\ReadModel;

use Logistics\SapConnector\Domain\Model\StockTransfer;

/**
 * Modelo de lectura de un traspaso de stock: forma plana en primitivos para APIs, listados y el panel
 * de reconciliación. Se arma desde el agregado; las consultas nunca exponen el objeto de dominio.
 */
final readonly class StockTransferView
{
    public function __construct(
        public string $id,
        public string $eventId,
        public string $shipmentRef,
        public string $material,
        public string $center,
        public string $warehouse,
        public int $quantity,
        public string $status,
        public ?string $lastError,
    ) {}

    public static function fromTransfer(StockTransfer $transfer): self
    {
        return new self(
            $transfer->id()->toString(),
            $transfer->eventId(),
            $transfer->shipmentRef(),
            $transfer->material()->value,
            $transfer->center()->value,
            $transfer->warehouse()->value,
            $transfer->quantity(),
            $transfer->status()->value,
            $transfer->lastError(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->eventId,
            'shipment_ref' => $this->shipmentRef,
            'material' => $this->material,
            'center' => $this->center,
            'warehouse' => $this->warehouse,
            'quantity' => $this->quantity,
            'status' => $this->status,
            'last_error' => $this->lastError,
        ];
    }
}
