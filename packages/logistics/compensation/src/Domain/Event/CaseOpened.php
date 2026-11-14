<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se abrió un caso de compensación (propuesto). El payload NO lleva el código del vale (aún no existe).
 */
final readonly class CaseOpened extends BaseDomainEvent
{
    public function __construct(
        string $caseId,
        public string $trigger,
        public string $scope,
        public ?string $orderReference,
        public ?string $shipmentId,
        public int $effectiveCents,
        public string $currency,
        public string $approvalLevel,
        public string $status,
        public ?string $ownership,
    ) {
        parent::__construct($caseId);
    }

    public function eventName(): string
    {
        return 'compensation.case_opened';
    }

    public function payload(): array
    {
        return [
            'trigger' => $this->trigger,
            'scope' => $this->scope,
            'order_reference' => $this->orderReference,
            'shipment_id' => $this->shipmentId,
            'effective_cents' => $this->effectiveCents,
            'currency' => $this->currency,
            'approval_level' => $this->approvalLevel,
            'status' => $this->status,
            'ownership' => $this->ownership,
        ];
    }
}
