<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se eliminó un tramo/corredor de la red. Planning debería dejar de proponerlo; network solo
 * registra el hecho.
 */
final readonly class LegRemoved extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'network.leg_removed';
    }

    /**
     * @return array<string, never>
     */
    public function payload(): array
    {
        return [];
    }
}
