<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se calculó la factura borrador de una orden (neto/IVA/total desde las líneas), aún sin número fiscal.
 */
final readonly class InvoiceDrafted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public int $subtotalCents,
        public int $taxCents,
        public int $totalCents,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'invoicing.invoice_drafted';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'subtotal_cents' => $this->subtotalCents,
            'tax_cents' => $this->taxCents,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
        ];
    }
}
