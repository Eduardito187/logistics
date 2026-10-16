<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se pagó al seller una liquidación: queda cerrada. Lleva la referencia del pago (p. ej. nº de transferencia).
 */
final readonly class SettlementPaid extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $paymentReference,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'settlement.paid';
    }

    public function payload(): array
    {
        return [
            'payment_reference' => $this->paymentReference,
        ];
    }
}
