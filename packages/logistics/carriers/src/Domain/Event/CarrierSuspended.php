<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se suspendió a un transportista: deja de recibir asignaciones hasta reactivarlo.
 */
final readonly class CarrierSuspended extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'carriers.carrier_suspended';
    }

    public function payload(): array
    {
        return [];
    }
}
