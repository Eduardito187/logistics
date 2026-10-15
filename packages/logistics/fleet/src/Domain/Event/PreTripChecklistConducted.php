<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se realizó un checklist pre-salida para un vehículo y un chofer en una fecha.
 *
 * `passed` indica si el checklist quedó completo (todos los ítems verificados): solo entonces la
 * tripulación está habilitada para salir a ruta.
 */
final readonly class PreTripChecklistConducted extends BaseDomainEvent
{
    /**
     * @param array<string, bool> $items
     */
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $driverId,
        public array $items,
        public bool $passed,
        public string $performedOn,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleet.pre_trip_checklist_conducted';
    }

    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'items' => $this->items,
            'passed' => $this->passed,
            'performed_on' => $this->performedOn,
        ];
    }
}
