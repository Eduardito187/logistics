<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Vehicle;

/**
 * Aptitud de un vehículo para despachar en una fecha. Forma ESTABLE (schema OpenAPI `VehicleFitness`).
 */
final readonly class VehicleFitnessView
{
    /**
     * @param list<string> $unfitItems
     */
    private function __construct(
        public string $vehicleId,
        public string $plate,
        public string $date,
        public bool $fitToDispatch,
        public ?string $unfitSince,
        public ?string $unfitReason,
        public array $unfitItems,
    ) {}

    public static function fromDomain(Vehicle $vehicle, DateTimeImmutable $date): self
    {
        $fitness = $vehicle->fitness();

        return new self(
            $vehicle->id()->toString(),
            $vehicle->plate()->value,
            $date->format('Y-m-d'),
            $fitness->isFitOn($date),
            $fitness->unfitSince?->format('Y-m-d'),
            $fitness->reason,
            $fitness->failedItems,
        );
    }

    /**
     * @return array{vehicle_id: string, plate: string, date: string, fit_to_dispatch: bool, unfit_since: string|null, unfit_reason: string|null, unfit_items: list<string>}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'plate' => $this->plate,
            'date' => $this->date,
            'fit_to_dispatch' => $this->fitToDispatch,
            'unfit_since' => $this->unfitSince,
            'unfit_reason' => $this->unfitReason,
            'unfit_items' => $this->unfitItems,
        ];
    }
}
