<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se definió (alta o reemplazo) una plantilla del checklist pre-salida.
 */
final readonly class ChecklistTemplateDefined extends BaseDomainEvent
{
    /**
     * @param list<array{key: string, label: string, category: string, criticality: string, requires_photo: bool}> $items
     */
    public function __construct(
        string $aggregateId,
        public string $name,
        public ?string $vehicleType,
        public bool $active,
        public array $items,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.checklist_template_defined';
    }

    public function payload(): array
    {
        return [
            'name' => $this->name,
            'vehicle_type' => $this->vehicleType,
            'active' => $this->active,
            'items' => $this->items,
        ];
    }
}
