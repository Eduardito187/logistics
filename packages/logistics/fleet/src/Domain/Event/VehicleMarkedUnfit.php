<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Un vehículo quedó NO APTO para despachar por un ítem CRÍTICO fallido en su checklist pre-salida.
 *
 * Desde `unfit_since` no se le arma tripulación ni se registra salida, hasta que un supervisor lo habilite
 * (evento fleet.vehicle_cleared). Otros contextos (planning, execution) pueden reaccionar por el outbox.
 */
final readonly class VehicleMarkedUnfit extends BaseDomainEvent
{
    /**
     * @param list<string> $failedItems
     */
    public function __construct(
        string $aggregateId,
        public string $plate,
        public string $unfitSince,
        public string $reason,
        public ?string $inspectionId,
        public array $failedItems,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.vehicle_marked_unfit';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->aggregateId,
            'plate' => $this->plate,
            'unfit_since' => $this->unfitSince,
            'reason' => $this->reason,
            'inspection_id' => $this->inspectionId,
            'failed_items' => $this->failedItems,
        ];
    }
}
