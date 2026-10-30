<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reembolsó un pago que estaba capturado (se reversó el cobro).
 */
final readonly class PaymentRefunded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public int $amountCents,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'payments.refunded';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
        ];
    }
}
