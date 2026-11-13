<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un caso de compensación fue aprobado (por el sistema en AUTO o por un aprobador con permiso).
 */
final readonly class CaseApproved extends BaseDomainEvent
{
    public function __construct(
        string $caseId,
        public string $approvedBy,
        public int $effectiveCents,
        public string $currency,
        public ?string $orderReference,
    ) {
        parent::__construct($caseId);
    }

    public function eventName(): string
    {
        return 'compensation.case_approved';
    }

    public function payload(): array
    {
        return [
            'approved_by' => $this->approvedBy,
            'effective_cents' => $this->effectiveCents,
            'currency' => $this->currency,
            'order_reference' => $this->orderReference,
        ];
    }
}
