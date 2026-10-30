<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se expiró una promoción: deja de aplicar y no vuelve a hacerlo.
 */
final readonly class PromotionExpired extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'promotions.promotion_expired';
    }

    public function payload(): array
    {
        return [];
    }
}
