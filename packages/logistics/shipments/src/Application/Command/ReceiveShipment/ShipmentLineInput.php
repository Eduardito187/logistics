<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\ReceiveShipment;

/**
 * Línea de pedido en el DTO de ingreso: primitivos tal como llegan de la fuente. El handler los
 * traduce a los value objects del dominio (Sku, ProductName, cantidad).
 */
final readonly class ShipmentLineInput
{
    public function __construct(
        public string $sku,
        public string $productName,
        public int $quantity,
    ) {}
}
