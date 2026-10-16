<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * La operación confirmó una liquidación de seller (queda pendiente de pago).
 */
final readonly class SettlementConfirmed extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'settlement.confirmed';
    }

    public function payload(): array
    {
        return [];
    }
}
