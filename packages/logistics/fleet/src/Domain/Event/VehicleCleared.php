<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un supervisor habilitó de nuevo un vehículo NO APTO tras la reparación: vuelve a ser asignable y
 * despachable. Lleva quién lo habilitó y la nota de la reparación (auditoría).
 */
final readonly class VehicleCleared extends BaseDomainEvent
{
    /**
     * @param list<string> $failedItems ítems que lo habían dejado no apto
     */
    public function __construct(
        string $aggregateId,
        public string $plate,
        public string $unfitSince,
        public array $failedItems,
        public ?string $clearedById,
        public string $clearedByLabel,
        public string $note,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.vehicle_cleared';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->aggregateId,
            'plate' => $this->plate,
            'unfit_since' => $this->unfitSince,
            'failed_items' => $this->failedItems,
            'cleared_by_id' => $this->clearedById,
            'cleared_by' => $this->clearedByLabel,
            'note' => $this->note,
        ];
    }
}
