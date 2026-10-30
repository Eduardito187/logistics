<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se inició un pago contra una orden (arranca en estado Pending).
 */
final readonly class PaymentInitiated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public int $amountCents,
        public string $currency,
        public string $method,
        public string $status,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'payments.initiated';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'method' => $this->method,
            'status' => $this->status,
        ];
    }
}
