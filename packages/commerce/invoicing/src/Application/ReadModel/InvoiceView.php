<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\ReadModel;

use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceLine;
use DateTimeImmutable;

/**
 * Proyección de lectura de una factura para listados y API. Primitivos: no expone objetos de dominio.
 *
 * Expone el desglose fiscal: `subtotal_cents` (neto), `tax_cents` (IVA) y `total_cents` (bruto, IVA
 * incluido), más las líneas con su total.
 */
final readonly class InvoiceView
{
    /**
     * @param list<array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}> $lines
     */
    private function __construct(
        public string $id,
        public string $orderId,
        public ?string $number,
        public string $customerName,
        public ?string $customerTaxId,
        public string $currency,
        public array $lines,
        public int $subtotalCents,
        public int $taxCents,
        public int $totalCents,
        public string $status,
        public ?string $issuedAt,
    ) {}

    public static function fromDomain(Invoice $invoice): self
    {
        return new self(
            $invoice->id()->toString(),
            $invoice->orderId()->value,
            $invoice->number(),
            $invoice->customerName(),
            $invoice->customerTaxId(),
            $invoice->currency(),
            array_values(array_map(
                static fn (InvoiceLine $line): array => $line->toArray(),
                $invoice->lines(),
            )),
            $invoice->subtotalCents(),
            $invoice->taxCents(),
            $invoice->totalCents(),
            $invoice->status()->value,
            $invoice->issuedAt()?->format(DateTimeImmutable::ATOM),
        );
    }

    /**
     * @return array{id: string, order_id: string, number: ?string, customer_name: string, customer_tax_id: ?string, currency: string, lines: list<array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}>, net_cents: int, iva_cents: int, total_cents: int, status: string, issued_at: ?string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order_id' => $this->orderId,
            'number' => $this->number,
            'customer_name' => $this->customerName,
            'customer_tax_id' => $this->customerTaxId,
            'currency' => $this->currency,
            'lines' => $this->lines,
            'net_cents' => $this->subtotalCents,
            'iva_cents' => $this->taxCents,
            'total_cents' => $this->totalCents,
            'status' => $this->status,
            'issued_at' => $this->issuedAt,
        ];
    }
}
