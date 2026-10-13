<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;

/**
 * Línea del envío: un producto con su cantidad. De cada línea salen tantas unidades físicas (bultos)
 * como cantidad: un bulto por unidad de producto.
 *
 * Entidad del agregado Shipment: tiene identidad propia pero no vive fuera del envío que la contiene.
 */
final class ShipmentLine
{
    private function __construct(
        private readonly ShipmentLineId $id,
        private readonly Sku $sku,
        private readonly ProductName $productName,
        private readonly int $quantity,
    ) {}

    public static function create(ShipmentLineId $id, Sku $sku, ProductName $productName, int $quantity): self
    {
        if ($quantity < 1) {
            throw ShipmentsException::because("la cantidad de la línea debe ser >= 1: {$quantity}");
        }

        return new self($id, $sku, $productName, $quantity);
    }

    public function id(): ShipmentLineId
    {
        return $this->id;
    }

    public function sku(): Sku
    {
        return $this->sku;
    }

    public function productName(): ProductName
    {
        return $this->productName;
    }

    public function quantity(): int
    {
        return $this->quantity;
    }
}
