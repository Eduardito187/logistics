<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\RecordVehiclePings;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de ingerir un lote de lecturas GPS de un vehículo (el app agrupa pings para ahorrar
 * batería/datos). Los pings llegan como arrays primitivos para no acoplar la entrada HTTP al dominio.
 *
 * @phpstan-type IncomingPing array{lat: float, lng: float, recorded_at: string, speed_kph?: float|null, heading?: int|null, accuracy_m?: float|null, ignition?: bool|null, odometer_m?: int|null, device_id?: string|null}
 */
final readonly class RecordVehiclePings implements Command
{
    /**
     * @param list<IncomingPing> $pings
     */
    public function __construct(
        public string $vehicleId,
        public ?string $driverId,
        public ?string $routeId,
        public string $source,
        public array $pings,
    ) {}
}
