<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se desbloqueó una franja horaria de despacho previamente bloqueada (global o de un municipio). La franja
 * vuelve a ofrecerse/agendarse normalmente.
 */
final readonly class DispatchSlotUnblocked extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $date,
        private string $timeSlot,
        private ?string $municipalityId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.dispatch_slot_unblocked';
    }

    /**
     * @return array{date: string, time_slot: string, municipality_id: string|null}
     */
    public function payload(): array
    {
        return [
            'date' => $this->date,
            'time_slot' => $this->timeSlot,
            'municipality_id' => $this->municipalityId,
        ];
    }
}
