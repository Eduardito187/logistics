<?php

declare(strict_types=1);

namespace Logistics\Sellers\Application\ReadModel;

use Logistics\Sellers\Domain\ValueObject\SellerSku;

/**
 * Proyección de lectura de un SKU del catálogo de un seller. Primitivos.
 */
final readonly class SellerSkuView
{
    private function __construct(
        public string $sellerId,
        public string $sku,
        public ?string $sapMaterial,
    ) {}

    public static function fromDomain(string $sellerId, SellerSku $sku): self
    {
        return new self($sellerId, $sku->sku, $sku->sapMaterial);
    }

    /**
     * @return array{seller_id: string, sku: string, sap_material: ?string}
     */
    public function toArray(): array
    {
        return [
            'seller_id' => $this->sellerId,
            'sku' => $this->sku,
            'sap_material' => $this->sapMaterial,
        ];
    }
}
