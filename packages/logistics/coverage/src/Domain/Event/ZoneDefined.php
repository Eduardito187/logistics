<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió una zona de cobertura nueva, con su polígono y sus reglas.
 */
final readonly class ZoneDefined extends BaseDomainEvent
{
    /**
     * @param array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>} $polygon
     * @param array<string, mixed>                                                                $rules
     */
    public function __construct(
        string $zoneId,
        public string $name,
        public ?string $parentAreaId,
        public array $polygon,
        public array $rules,
    ) {
        parent::__construct($zoneId);
    }

    public function eventName(): string
    {
        return 'coverage.zone_defined';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'zoneId' => $this->aggregateId,
            'name' => $this->name,
            'parentAreaId' => $this->parentAreaId,
            'polygon' => $this->polygon,
            'rules' => $this->rules,
        ];
    }
}
