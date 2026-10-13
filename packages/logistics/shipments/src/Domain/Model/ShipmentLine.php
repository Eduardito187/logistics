<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Model;

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\Sku;

/**
 * Línea del envío: un producto con su cantidad. De cada línea salen tantas unidades físicas (bultos)
 * como cantidad: un bulto por unidad de producto.
 *
 * Entidad del agregado Shipment: tiene identidad propia pero no vive fuera del envío que la contiene.
 *
 * La imagen del producto y el precio unitario son datos OPCIONALES de presentación (por defecto null):
 * los consume el notificador para mostrar productos con miniatura en mail/push y precio en el detalle.
 * Su ausencia nunca rompe la ingesta existente.
 */
final class ShipmentLine
{
    private function __construct(
        private readonly ShipmentLineId $id,
        private readonly Sku $sku,
        private readonly ProductName $productName,
        private readonly int $quantity,
        private readonly ?ProductImageUrl $imageUrl,
        private readonly ?Money $unitPrice,
    ) {}

    public static function create(
        ShipmentLineId $id,
        Sku $sku,
        ProductName $productName,
        int $quantity,
        ?ProductImageUrl $imageUrl = null,
        ?Money $unitPrice = null,
    ): self {
        if ($quantity < 1) {
            throw ShipmentsException::because("la cantidad de la línea debe ser >= 1: {$quantity}");
        }

        return new self($id, $sku, $productName, $quantity, $imageUrl, $unitPrice);
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

    /**
     * URL de la imagen del producto, o null si la fuente no la trae.
     */
    public function imageUrl(): ?ProductImageUrl
    {
        return $this->imageUrl;
    }

    /**
     * Precio unitario del producto, o null si la fuente no lo trae.
     */
    public function unitPrice(): ?Money
    {
        return $this->unitPrice;
    }
}
