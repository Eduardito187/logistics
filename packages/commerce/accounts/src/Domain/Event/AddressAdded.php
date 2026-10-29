<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se agregó una dirección de compra/envío a una cuenta.
 */
final readonly class AddressAdded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $label,
        public bool $isDefault,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'accounts.address_added';
    }

    public function payload(): array
    {
        return [
            'label' => $this->label,
            'is_default' => $this->isDefault,
        ];
    }
}
