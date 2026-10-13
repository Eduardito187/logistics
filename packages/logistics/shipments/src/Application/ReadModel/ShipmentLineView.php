<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Modelo de lectura de una línea del envío. Solo primitivos, listo para serializar a JSON.
 */
final readonly class ShipmentLineView
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $productName,
        public int $quantity,
    ) {}

    /**
     * @return array{id: string, sku: string, product_name: string, quantity: int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
        ];
    }
}
