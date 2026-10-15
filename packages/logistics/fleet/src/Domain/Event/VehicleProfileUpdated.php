<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se actualizó la ficha técnica (perfil) de un vehículo.
 *
 * El payload ya viene en forma primitiva desde `VehicleProfile::toArray()`.
 */
final readonly class VehicleProfileUpdated extends BaseDomainEvent
{
    /**
     * @param array<string, mixed> $profile
     */
    public function __construct(
        string $aggregateId,
        public array $profile,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.vehicle.profile_updated';
    }

    public function payload(): array
    {
        return $this->profile;
    }
}
