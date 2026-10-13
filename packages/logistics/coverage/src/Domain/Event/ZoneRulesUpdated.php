<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Cambiaron las reglas de operación de una zona (nodo, servicios, tiempos, límites, horarios, días).
 */
final readonly class ZoneRulesUpdated extends BaseDomainEvent
{
    /**
     * @param array<string, mixed> $rules
     */
    public function __construct(
        string $zoneId,
        public array $rules,
    ) {
        parent::__construct($zoneId);
    }

    public function eventName(): string
    {
        return 'coverage.zone_rules_updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'zoneId' => $this->aggregateId,
            'rules' => $this->rules,
        ];
    }
}
