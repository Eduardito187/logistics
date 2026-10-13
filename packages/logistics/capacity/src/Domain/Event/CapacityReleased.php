<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se liberó capacidad de un bucket (p. ej. al reprogramar o cuando un envío falla). Lleva la cantidad
 * devuelta y el estado resultante (consumido/disponible).
 */
final readonly class CapacityReleased extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $unit,
        private float $amount,
        private float $used,
        private float $available,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.capacity_released';
    }

    /**
     * @return array{unit: string, amount: float, used: float, available: float}
     */
    public function payload(): array
    {
        return [
            'unit' => $this->unit,
            'amount' => $this->amount,
            'used' => $this->used,
            'available' => $this->available,
        ];
    }
}
