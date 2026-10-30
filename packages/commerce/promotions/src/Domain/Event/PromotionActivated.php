<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó una promoción: a partir de ahora aplica si se cumplen sus condiciones.
 */
final readonly class PromotionActivated extends BaseDomainEvent
{
    public function eventName(): string
    {
        return 'promotions.promotion_activated';
    }

    public function payload(): array
    {
        return [];
    }
}
