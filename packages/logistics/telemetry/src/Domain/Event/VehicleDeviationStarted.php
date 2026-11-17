<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * El vehículo EMPEZÓ a desviarse de la geometría de su ruta (arrancó la cuenta `off_route_since`), antes de
 * que el desvío sea sostenido. Es distinto de `telemetry.vehicle_route_deviated`, que recién se emite cuando
 * el desvío cumple el mínimo sostenido (y levanta la alerta). Este hecho, en el INSTANTE del desvío, lo usa
 * telemetría para recalcular YA (async, por el outbox) la geometría del reruteo por calles —así la línea del
 * mapa deja de ser la recta de respaldo apenas el camión se sale, sin esperar el barrido del minuto.
 */
final readonly class VehicleDeviationStarted extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $vehicleId,
        public string $sinceIso,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'telemetry.vehicle_deviation_started';
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'since' => $this->sinceIso,
        ];
    }
}
