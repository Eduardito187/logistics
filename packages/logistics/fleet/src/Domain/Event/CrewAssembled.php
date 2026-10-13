<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se armó una tripulación (chofer + ayudantes) para un vehículo y una fecha.
 */
final readonly class CrewAssembled extends BaseDomainEvent
{
    /**
     * @param list<string> $helperIds
     */
    public function __construct(
        string $aggregateId,
        public string $driverId,
        public array $helperIds,
        public string $vehicleId,
        public string $assembledFor,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.crew_assembled';
    }

    public function payload(): array
    {
        return [
            'driver_id' => $this->driverId,
            'helper_ids' => $this->helperIds,
            'vehicle_id' => $this->vehicleId,
            'assembled_for' => $this->assembledFor,
        ];
    }
}
