<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Dto;

/**
 * Línea de un pedido leído de SAP, ya traducida al modelo propio (primitivos). El sku es el sku propio
 * de la plataforma (resuelto desde el material SAP por el mapa de traducción), no el material SAP.
 */
final readonly class ImportedOrderLineDto
{
    public function __construct(
        public string $sku,
        public string $productName,
        public int $quantity,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'quantity' => $this->quantity,
        ];
    }
}
