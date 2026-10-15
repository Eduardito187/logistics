<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\ReceiveShipment;

use Logistics\Shared\Application\Command\Command;

/**
 * Comando de ingreso de un pedido, ya normalizado a primitivos (la fuente concreta —SAP, Magento,
 * tienda, Excel— ya se tradujo a este DTO). Es la API ÚNICA de entrada de pedidos del contexto.
 *
 * Idempotente por (source, externalId): reingresar el mismo pedido no crea un envío nuevo.
 */
final readonly class ReceiveShipment implements Command
{
    /**
     * @param list<ShipmentLineInput> $lines
     */
    public function __construct(
        public string $source,
        public string $externalId,
        public string $customerName,
        public string $addressText,
        public ?string $addressReference = null,
        public ?float $lat = null,
        public ?float $lng = null,
        public array $lines = [],
        // Datos de contacto del cliente: opcionales (las fuentes mínimas no siempre los traen).
        public ?string $customerDocumentId = null,
        public ?string $customerPhone = null,
        public ?string $customerEmail = null,
    ) {}
}
