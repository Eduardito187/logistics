<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * A un chofer le cambiaron el camión del día: su tripulación anterior quedó sin efecto y la nueva (el
 * agregado de este evento) la reemplaza. Lleva el camión anterior y el nuevo para que la actividad
 * muestre el cambio completo.
 */
final readonly class CrewVehicleChanged extends BaseDomainEvent
{
    /**
     * @param list<string>                                   $helperIds
     * @param array{starts_at: string, ends_at: string}|null $shift
     */
    public function __construct(
        string $aggregateId,
        public string $previousCrewId,
        public string $driverId,
        public string $previousVehicleId,
        public string $vehicleId,
        public array $helperIds,
        public string $assembledFor,
        public ?array $shift = null,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.crew_vehicle_changed';
    }

    public function payload(): array
    {
        return [
            'previous_crew_id' => $this->previousCrewId,
            'driver_id' => $this->driverId,
            'previous_vehicle_id' => $this->previousVehicleId,
            'vehicle_id' => $this->vehicleId,
            'helper_ids' => $this->helperIds,
            'assembled_for' => $this->assembledFor,
            'shift' => $this->shift,
        ];
    }
}
