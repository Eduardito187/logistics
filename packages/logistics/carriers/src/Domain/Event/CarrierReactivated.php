<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se reactivó a un transportista suspendido: vuelve a estar disponible para asignaciones.
 */
final readonly class CarrierReactivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'carriers.carrier_reactivated';
    }

    public function payload(): array
    {
        return [];
    }
}
