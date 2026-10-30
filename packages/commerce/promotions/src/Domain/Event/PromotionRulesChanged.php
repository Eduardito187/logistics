<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambiaron las reglas de una promoción (tipo, valor o condiciones). El payload lleva la nueva versión
 * para que los consumidores aguas abajo sepan contra qué versión están evaluando (versionado).
 */
final readonly class PromotionRulesChanged extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $type,
        public int $value,
        public int $version,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'promotions.promotion_rules_changed';
    }

    public function payload(): array
    {
        return [
            'type' => $this->type,
            'value' => $this->value,
            'version' => $this->version,
        ];
    }
}
