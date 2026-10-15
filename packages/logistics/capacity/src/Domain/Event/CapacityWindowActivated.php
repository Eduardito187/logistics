<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se activó (o extendió) el horizonte de cupos de un municipio (o global) hasta una fecha. Marca que
 * "hay cupos activos hasta <active_until>" para ese alcance. Lo consumen, vía outbox, quienes ofrecen
 * slots o la auditoría operativa.
 */
final readonly class CapacityWindowActivated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private ?string $municipalityId,
        private string $activeUntil,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.capacity_window_activated';
    }

    /**
     * @return array{municipality_id: string|null, active_until: string}
     */
    public function payload(): array
    {
        return [
            'municipality_id' => $this->municipalityId,
            'active_until' => $this->activeUntil,
        ];
    }
}
