<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\EnqueueStockTransfer;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando para encolar un traspaso de stock al recibir un bulto en un nodo. Llega con el sku propio;
 * el handler lo traduce a la coordenada SAP (material + centro + almacén) con el mapa de traducción.
 *
 * Idempotente por `eventId` (el event_id de la recepción): reprocesar la misma recepción no duplica.
 */
final readonly class EnqueueStockTransfer implements Command
{
    public function __construct(
        public string $eventId,
        public string $shipmentRef,
        public string $sku,
        public int $quantity,
    ) {}
}
