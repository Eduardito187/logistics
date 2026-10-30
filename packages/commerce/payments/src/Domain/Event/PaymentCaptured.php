<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se capturó (confirmó) el cobro de un pago.
 *
 * Es el hecho de negocio que, vía outbox, confirma la orden pagada aguas abajo (puente OMS→Shipment de
 * ADR 018). El puente que lo consume es idempotente por `order_id`; su payload lleva lo necesario para
 * cerrar la orden sin volver a consultar a payments.
 */
final readonly class PaymentCaptured extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $orderId,
        public int $amountCents,
        public string $currency,
        public string $gatewayRef,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'payments.captured';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->orderId,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'gateway_ref' => $this->gatewayRef,
        ];
    }
}
