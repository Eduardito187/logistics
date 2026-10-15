<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se actualizaron los datos personales (perfil) de un ayudante.
 *
 * El payload ya viene en forma primitiva desde `HelperProfile::toArray()`.
 */
final readonly class HelperProfileUpdated extends BaseDomainEvent
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
        return 'fleet.helper.profile_updated';
    }

    public function payload(): array
    {
        return $this->profile;
    }
}
