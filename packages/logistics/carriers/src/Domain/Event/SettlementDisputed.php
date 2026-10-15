<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se objetó una liquidación de transportista por una discrepancia (hay que resolverla antes de aprobar).
 */
final readonly class SettlementDisputed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.settlement_disputed';
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
