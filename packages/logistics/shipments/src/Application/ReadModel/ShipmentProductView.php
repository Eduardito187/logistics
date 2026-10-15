<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Modelo de lectura de un PRODUCTO de un envío para el notificador: lo mínimo para armar el detalle
 * de productos del mail (listado con miniatura y precio) y el collage de hasta 4 ítems del push.
 *
 * Solo primitivos. imageUrl/price son opcionales (null si la fuente no los mandó). El notificador lo
 * consume por su propio contrato (un adapter que llama al QueryBus con ListShipmentProducts), sin
 * conocer el modelo de dominio de este contexto.
 */
final readonly class ShipmentProductView
{
    public function __construct(
        public string $sku,
        public string $name,
        public int $qty,
        public ?string $imageUrl = null,
        public ?float $price = null,
        public ?string $currency = null,
    ) {}

    /**
     * @return array{sku: string, name: string, qty: int, image_url: string|null, price: float|null, currency: string|null}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'name' => $this->name,
            'qty' => $this->qty,
            'image_url' => $this->imageUrl,
            'price' => $this->price,
            'currency' => $this->currency,
        ];
    }
}
