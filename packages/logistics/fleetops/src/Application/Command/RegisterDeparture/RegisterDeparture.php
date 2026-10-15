<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RegisterDeparture;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar la salida/despacho de un vehículo de flota propia.
 *
 * La carga llega como forma primitiva {description?, weight_kg?, items[]} para no acoplar la entrada al
 * dominio; los ayudantes como lista de ids (strings). La fecha de salida llega en ISO 8601.
 */
final readonly class RegisterDeparture implements Command
{
    /**
     * @param list<string>                                                                                              $helperIds
     * @param array{description?: string|null, weight_kg?: float|int|null, items?: list<array{name: string, qty: int}>} $load
     */
    public function __construct(
        public string $vehicleId,
        public ?string $driverId,
        public array $helperIds,
        public ?string $destination,
        public ?string $routeRef,
        public array $load,
        public ?int $odometerOutKm,
        public string $departedAt,
        public ?string $note = null,
    ) {}
}
