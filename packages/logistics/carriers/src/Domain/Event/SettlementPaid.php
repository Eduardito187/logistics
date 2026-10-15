<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se pagó una liquidación de transportista: queda cerrada.
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
        return 'carriers.settlement_paid';
    }

    public function payload(): array
    {
        return [
            'payment_reference' => $this->paymentReference,
        ];
    }
}
