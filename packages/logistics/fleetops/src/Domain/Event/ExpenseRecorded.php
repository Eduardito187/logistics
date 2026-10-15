<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un gasto de flota (peaje, multa, llantas, etc.) contra un vehículo.
 */
final readonly class ExpenseRecorded extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $category,
        public int $amountCents,
        public string $currency,
        public string $incurredAt,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.expense_recorded';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'category' => $this->category,
            'amount_cents' => $this->amountCents,
            'currency' => $this->currency,
            'incurred_at' => $this->incurredAt,
        ];
    }
}
