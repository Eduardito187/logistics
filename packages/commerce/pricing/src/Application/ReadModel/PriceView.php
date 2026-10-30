<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\ReadModel;

use Commerce\Pricing\Domain\ValueObject\Money;

/**
 * Proyección de lectura de un precio (ítem) de una lista: el precio al público —IVA incluido— de un SKU.
 * Primitivos.
 */
final readonly class PriceView
{
    private function __construct(
        public string $priceListId,
        public string $sku,
        public int $grossAmountCents,
        public string $currency,
    ) {}

    public static function fromPrimitives(string $priceListId, string $sku, Money $grossAmount): self
    {
        return new self($priceListId, $sku, $grossAmount->cents, $grossAmount->currency);
    }

    /**
     * @return array{price_list_id: string, sku: string, gross_amount_cents: int, currency: string}
     */
    public function toArray(): array
    {
        return [
            'price_list_id' => $this->priceListId,
            'sku' => $this->sku,
            'gross_amount_cents' => $this->grossAmountCents,
            'currency' => $this->currency,
        ];
    }
}
