<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se desbloqueó un día de despacho previamente bloqueado (global o de un municipio). El día vuelve a
 * ofrecerse/agendarse normalmente.
 */
final readonly class DispatchDayUnblocked extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $date,
        private ?string $municipalityId,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'capacity.dispatch_day_unblocked';
    }

    /**
     * @return array{date: string, municipality_id: string|null}
     */
    public function payload(): array
    {
        return [
            'date' => $this->date,
            'municipality_id' => $this->municipalityId,
        ];
    }
}
