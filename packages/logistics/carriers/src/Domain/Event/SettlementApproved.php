<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La operación aprobó una liquidación de transportista (queda pendiente de pago).
 */
final readonly class SettlementApproved extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'carriers.settlement_approved';
    }

    public function payload(): array
    {
        return [];
    }
}
