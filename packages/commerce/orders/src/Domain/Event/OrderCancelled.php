<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Event;

use DateTimeImmutable;
use Logistics\Shared\Domain\Event\BaseDomainEvent;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Se canceló la orden. La cancelación se permite en cualquier estado no terminal (pre y post pago),
 * por eso el payload lleva `was_paid`: les dice a los reactores cruzados (logística, pagos, cupo) si
 * la orden ya estaba pagada y, por tanto, si corresponde reembolso / retorno (ver ADR 020).
 *
 * El payload es un contrato de outbox: `{order_id, was_paid, reason, source}`. Es backward-compatible,
 * los campos nuevos tienen default, de modo que `new OrderCancelled($orderId)` sigue siendo válido.
 */
final readonly class OrderCancelled extends BaseDomainEvent
{
    /**
     * @param string      $orderId id de la orden cancelada (también es el aggregateId del evento)
     * @param bool        $wasPaid el estado previo era Paid o Fulfilling (dispara reembolso/retorno)
     * @param string|null $reason  motivo opcional (p. ej. `unpaid_timeout` en la expiración por cron)
     * @param string      $source  origen de la cancelación: `pos`, `manual`, `cron`, `customer`
     */
    public function __construct(
        string $orderId,
        public bool $wasPaid = false,
        public ?string $reason = null,
        public string $source = 'manual',
        ?Uuid $eventId = null,
        ?DateTimeImmutable $occurredAt = null,
    ) {
        parent::__construct($orderId, $eventId, $occurredAt);
    }

    public function eventName(): string
    {
        return 'orders.order_cancelled';
    }

    public function payload(): array
    {
        return [
            'order_id' => $this->aggregateId,
            'was_paid' => $this->wasPaid,
            'reason' => $this->reason,
            'source' => $this->source,
        ];
    }
}
