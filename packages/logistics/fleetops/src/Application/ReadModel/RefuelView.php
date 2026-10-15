<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Refuel;

/**
 * Proyección de lectura de una carga de combustible para listados y API. Primitivos: no expone objetos
 * de dominio. El dinero viaja como centavos + moneda.
 */
final readonly class RefuelView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public ?string $driverId,
        public string $fuelType,
        public float $liters,
        public int $pricePerLiterCents,
        public int $totalCostCents,
        public string $currency,
        public ?int $odometerKm,
        public ?string $station,
        public bool $fullTank,
        public string $refueledAt,
        public ?string $note,
    ) {}

    public static function fromDomain(Refuel $refuel): self
    {
        return new self(
            $refuel->id()->toString(),
            $refuel->vehicleId(),
            $refuel->driverId(),
            $refuel->fuelType()->value,
            $refuel->liters(),
            $refuel->pricePerLiter()->cents,
            $refuel->totalCost()->cents,
            $refuel->totalCost()->currency,
            $refuel->odometer()?->km,
            $refuel->station(),
            $refuel->isFullTank(),
            $refuel->refueledAt()->format(DateTimeImmutable::ATOM),
            $refuel->note(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, fuel_type: string, liters: float, price_per_liter_cents: int, total_cost_cents: int, currency: string, odometer_km: int|null, station: string|null, full_tank: bool, refueled_at: string, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'fuel_type' => $this->fuelType,
            'liters' => $this->liters,
            'price_per_liter_cents' => $this->pricePerLiterCents,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'odometer_km' => $this->odometerKm,
            'station' => $this->station,
            'full_tank' => $this->fullTank,
            'refueled_at' => $this->refueledAt,
            'note' => $this->note,
        ];
    }
}
