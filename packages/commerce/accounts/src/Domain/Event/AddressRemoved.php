<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se quitó una dirección de una cuenta (identificada por su etiqueta).
 */
final readonly class AddressRemoved extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $label,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'accounts.address_removed';
    }

    public function payload(): array
    {
        return [
            'label' => $this->label,
        ];
    }
}
