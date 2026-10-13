<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un transportista tercero nuevo.
 */
final readonly class CarrierRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $taxId,
        public string $status,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_registered';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'tax_id' => $this->taxId,
            'status' => $this->status,
        ];
    }
}
