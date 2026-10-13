<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Command\RecordRefuel;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de registrar una carga de combustible de un vehículo de la flota propia.
 *
 * El vehículo y el conductor llegan como sus ids en texto; el precio por litro como centavos + moneda, y
 * el odómetro como km enteros (opcional); el handler los convierte a los VO del dominio.
 */
final readonly class RecordRefuel implements Command
{
    public function __construct(
        public string $vehicleId,
        public ?string $driverId,
        public string $fuelType,
        public float $liters,
        public int $pricePerLiterCents,
        public string $currency,
        public ?int $odometerKm,
        public ?string $station,
        public bool $fullTank,
        public string $refueledAt,
        public ?string $note = null,
    ) {}
}
