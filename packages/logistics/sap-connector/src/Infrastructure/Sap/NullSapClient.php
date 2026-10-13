<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Sap;

use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Psr\Log\LoggerInterface;

/**
 * Implementación por defecto del puerto SAP: no habla con SAP, solo deja rastro en el log y devuelve
 * cero pedidos. Es el binding inicial pensado para desarrollo y pruebas: deja ver el flujo del conector
 * (encolar, despachar, reconciliar) sin salir de la plataforma. El cliente real reemplaza este binding.
 */
final readonly class NullSapClient implements SapClient
{
    public function __construct(
        private ?LoggerInterface $logger = null,
    ) {}

    public function pushStockTransfer(StockTransfer $transfer): void
    {
        $this->logger?->debug('Stock transfer discarded by NullSapClient', [
            'id' => $transfer->id()->toString(),
            'event_id' => $transfer->eventId(),
            'shipment_ref' => $transfer->shipmentRef(),
            'material' => $transfer->material()->value,
            'center' => $transfer->center()->value,
            'warehouse' => $transfer->warehouse()->value,
            'quantity' => $transfer->quantity(),
        ]);
    }

    public function fetchOrders(): array
    {
        $this->logger?->debug('NullSapClient returned no orders');

        return [];
    }
}
