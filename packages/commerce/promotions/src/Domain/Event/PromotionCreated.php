<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se creó una promoción nueva en estado borrador (aún no aplica a ninguna evaluación).
 */
final readonly class PromotionCreated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $name,
        public ?string $code,
        public string $type,
        public int $value,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'promotions.promotion_created';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,
            'currency' => $this->currency,
        ];
    }
}
