<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se anuló una factura.
 */
final readonly class InvoiceCancelled extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'invoicing.invoice_cancelled';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
        ];
    }
}
