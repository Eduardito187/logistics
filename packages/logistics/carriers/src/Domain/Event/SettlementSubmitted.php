<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un transportista envió una liquidación para un corredor y período (pendiente de revisión).
 */
final readonly class SettlementSubmitted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $carrierId,
        public string $corridorFrom,
        public string $corridorTo,
        public string $corridorType,
        public string $period,
        public int $amountCents,
        public string $currency,
        public string $reference,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.settlement_submitted';
    }

    public function payload(): array
    {
        return [
            'carrier_id' => $this->carrierId,
            'corridor_from' => $this->corridorFrom,
            'corridor_to' => $this->corridorTo,
            'corridor_type' => $this->corridorType,
            'period' => $this->period,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'reference' => $this->reference,
        ];
    }
}
