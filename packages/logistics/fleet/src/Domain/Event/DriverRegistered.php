<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un chofer nuevo en la flota.
 */
final readonly class DriverRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public string $licenseNumber,
        public string $licenseExpiry,
        public bool $active,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.driver_registered';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'license_number' => $this->licenseNumber,
            'license_expiry' => $this->licenseExpiry,
            'active' => $this->active,
        ];
    }
}
