<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se cerró una salida: el vehículo retornó, se tomó el odómetro de regreso y se calcularon los km recorridos.
 */
final readonly class DepartureClosed extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public int $odometerInKm,
        // Km recorridos en la salida; nulo si no se había registrado odómetro de salida.
        public ?int $kmTraveled,
        // Momento de retorno en ISO 8601; distinto de `occurredAt` del evento base.
        public string $returnedAtIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.departure_closed';
    }

    public function payload(): array
    {
        return [
            'odometer_in_km' => $this->odometerInKm,
            'km_traveled' => $this->kmTraveled,
            'returned_at' => $this->returnedAtIso,
        ];
    }
}
