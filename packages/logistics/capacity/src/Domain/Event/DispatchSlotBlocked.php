<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se bloqueó una franja horaria de despacho (sin bloquear el día entero), global o para un municipio. Lo
 * consumen, vía outbox, quienes ofrecen slots o planifican, para dejar de ofrecer/agendar esa franja.
 */
final readonly class DispatchSlotBlocked extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $date,
        private string $timeSlot,
        private ?string $municipalityId,
        private string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.dispatch_slot_blocked';
    }

    /**
     * @return array{date: string, time_slot: string, municipality_id: string|null, reason: string}
     */
    public function payload(): array
    {
        return [
            'date' => $this->date,
            'time_slot' => $this->timeSlot,
            'municipality_id' => $this->municipalityId,
            'reason' => $this->reason,
        ];
    }
}
