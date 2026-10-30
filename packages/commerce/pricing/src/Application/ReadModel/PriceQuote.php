<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\ReadModel;

use Commerce\Pricing\Domain\ValueObject\Money;
use Commerce\Pricing\Domain\ValueObject\TaxPolicy;

/**
 * Presupuesto de precio de un SKU en un canal: el precio al público (bruto, IVA incluido) descompuesto en
 * neto + IVA, más el IT, según la política tributaria de Bolivia. Primitivos.
 *
 * `taxCents` es el IVA contenido en el precio (el impuesto que "lleva por dentro" el bruto). El IT se
 * reporta aparte, en `taxBreakdown`, porque no se descuenta del neto (es un impuesto adicional).
 */
final readonly class PriceQuote
{
    /**
     * @param array{iva_cents: int, iva_basis_points: int, it_cents: int, it_basis_points: int} $taxBreakdown
     */
    private function __construct(
        public string $sku,
        public string $channel,
        public int $netCents,
        public int $taxCents,
        public int $grossCents,
        public string $currency,
        public array $taxBreakdown,
    ) {}

    /**
     * Arma el presupuesto a partir del precio bruto (IVA incluido) y la política tributaria.
     */
    public static function fromGross(string $sku, string $channel, Money $gross, TaxPolicy $taxPolicy): self
    {
        return new self(
            $sku,
            $channel,
            $taxPolicy->netOf($gross)->cents,
            $taxPolicy->ivaOn($gross)->cents,
            $gross->cents,
            $gross->currency,
            $taxPolicy->breakdown($gross),
        );
    }

    /**
     * @return array{sku: string, channel: string, net_cents: int, tax_cents: int, gross_cents: int, currency: string, tax_breakdown: array{iva_cents: int, iva_basis_points: int, it_cents: int, it_basis_points: int}}
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'channel' => $this->channel,
            'net_cents' => $this->netCents,
            'tax_cents' => $this->taxCents,
            'gross_cents' => $this->grossCents,
            'currency' => $this->currency,
            'tax_breakdown' => $this->taxBreakdown,
        ];
    }
}
