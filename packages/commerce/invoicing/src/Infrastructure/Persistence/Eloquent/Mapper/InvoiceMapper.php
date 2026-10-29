<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Mapper;

use Commerce\Invoicing\Domain\Model\Invoice;
use Commerce\Invoicing\Domain\ValueObject\InvoiceId;
use Commerce\Invoicing\Domain\ValueObject\InvoiceLine;
use Commerce\Invoicing\Domain\ValueObject\InvoiceStatus;
use Commerce\Invoicing\Infrastructure\Persistence\Eloquent\Model\InvoiceRecord;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Traduce entre el agregado Invoice (dominio) y su fila Eloquent. Único punto que conoce ambos lados.
 */
final class InvoiceMapper
{
    public function toDomain(InvoiceRecord $record): Invoice
    {
        // El cast 'issued_at' => 'datetime' entrega un Carbon: se convierte a DateTimeImmutable (nunca
        // `new DateTimeImmutable($record->issued_at)`, que reventaría con el Carbon — gotcha de la skill).
        $issuedAt = $record->issued_at?->toDateTimeImmutable();

        return Invoice::reconstitute(
            InvoiceId::fromString($record->id),
            Uuid::fromString($record->order_id),
            $record->number,
            $record->customer_name,
            $record->customer_tax_id,
            $record->currency,
            array_values(array_map(
                static fn (array $line): InvoiceLine => InvoiceLine::fromArray($line),
                $record->lines,
            )),
            $record->subtotal_cents,
            $record->tax_cents,
            $record->total_cents,
            InvoiceStatus::from($record->status),
            $issuedAt,
        );
    }

    /**
     * Fila de la tabla `commerce_invoices`.
     *
     * @return array{id: string, order_id: string, number: ?string, customer_name: string, customer_tax_id: ?string, currency: string, lines: list<array{description: string, quantity: int, unit_price_cents: int, line_total_cents: int}>, subtotal_cents: int, tax_cents: int, total_cents: int, status: string, issued_at: ?string}
     */
    public function toRow(Invoice $invoice): array
    {
        return [
            'id' => $invoice->id()->toString(),
            'order_id' => $invoice->orderId()->value,
            'number' => $invoice->number(),
            'customer_name' => $invoice->customerName(),
            'customer_tax_id' => $invoice->customerTaxId(),
            'currency' => $invoice->currency(),
            'lines' => array_values(array_map(
                static fn (InvoiceLine $line): array => $line->toArray(),
                $invoice->lines(),
            )),
            'subtotal_cents' => $invoice->subtotalCents(),
            'tax_cents' => $invoice->taxCents(),
            'total_cents' => $invoice->totalCents(),
            'status' => $invoice->status()->value,
            'issued_at' => $invoice->issuedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}
