<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Modelo de lectura de una línea del envío. Solo primitivos, listo para serializar a JSON.
 *
 * imageUrl y price son opcionales (null si la fuente no los mandó); los consume el notificador para
 * mostrar los productos con miniatura (mail/push) y su precio en el detalle.
 */
final readonly class ShipmentLineView
{
    public function __construct(
        public string $id,
        public string $sku,
        public string $productName,
        public int $quantity,
        public ?string $imageUrl = null,
        public ?float $price = null,
        public ?string $currency = null,
    ) {}

    /**
     * @return array{id: string, sku: string, product_name: string, quantity: int, image_url: string|null, price: float|null, currency: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
            'image_url' => $this->imageUrl,
            'price' => $this->price,
            'currency' => $this->currency,
        ];
    }
}
