<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se bloqueó un día de despacho (feriado u otro motivo), global o para un municipio. Lo consumen, vía
 * outbox, quienes ofrecen slots o planifican, para dejar de ofrecer/agendar ese día.
 */
final readonly class DispatchDayBlocked extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $date,
        private ?string $municipalityId,
        private string $reason,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.dispatch_day_blocked';
    }

    /**
     * @return array{date: string, municipality_id: string|null, reason: string}
     */
    public function payload(): array
    {
        return [
            'date' => $this->date,
            'municipality_id' => $this->municipalityId,
            'reason' => $this->reason,
        ];
    }
}
