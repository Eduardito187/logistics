<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se suspendió un seller: queda bloqueado hasta que se lo reactive.
 */
final readonly class SellerSuspended extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'sellers.seller_suspended';
    }

    public function payload(): array
    {
        return [];
    }
}
