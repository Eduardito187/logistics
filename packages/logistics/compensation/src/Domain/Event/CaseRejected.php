<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un caso de compensación fue rechazado por un aprobador.
 */
final readonly class CaseRejected extends BaseDomainEvent
{
    public function __construct(
        string $caseId,
        public string $rejectedBy,
        public string $reason,
    ) {
        parent::__construct($caseId);
    }

    public function eventName(): string
    {
        return 'compensation.case_rejected';
    }

    public function payload(): array
    {
        return [
            'rejected_by' => $this->rejectedBy,
            'reason' => $this->reason,
        ];
    }
}
