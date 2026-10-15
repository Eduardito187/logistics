<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Command\ReceiveShipment;

/**
 * Línea de pedido en el DTO de ingreso: primitivos tal como llegan de la fuente. El handler los
 * traduce a los value objects del dominio (Sku, ProductName, cantidad, imagen y precio).
 *
 * La URL de imagen y el precio unitario son OPCIONALES (por defecto null): las fuentes que no los
 * mandan (ingesta histórica, Excel mínimo) siguen funcionando igual. Los consume el notificador.
 */
final readonly class ShipmentLineInput
{
    public function __construct(
        public string $sku,
        public string $productName,
        public int $quantity,
        public ?string $imageUrl = null,
        public ?float $price = null,
        public ?string $currency = null,
    ) {}
}
