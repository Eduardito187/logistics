<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El pago de la orden fue confirmado. Es el evento de integración que dispara el puente a fulfillment
 * (un consumidor en Infrastructure crea el envío reutilizando `shipments`, idempotente por order_id).
 */
final readonly class OrderPaid extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $gatewayRef,
        public int $totalCents,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'orders.order_paid';
    }

    public function payload(): array
    {
        return [
            'gateway_ref' => $this->gatewayRef,
            'total_cents' => $this->totalCents,
            'currency' => $this->currency,
        ];
    }
}
