<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Falló el cobro de un pago (la pasarela lo rechazó).
 */
final readonly class PaymentFailed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'payments.failed';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'reason' => $this->reason,
        ];
    }
}
