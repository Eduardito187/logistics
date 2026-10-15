<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un ayudante nuevo en la flota.
 */
final readonly class HelperRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public ?string $phone,
        public bool $active,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.helper_registered';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'phone' => $this->phone,
            'active' => $this->active,
        ];
    }
}
