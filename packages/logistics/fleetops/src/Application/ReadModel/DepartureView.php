<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Departure;

/**
 * Proyección de lectura de una salida para listados, admin y API. Primitivos: no expone objetos de
 * dominio. Las fechas salen en ISO 8601 (ATOM).
 */
final readonly class DepartureView
{
    /**
     * @param list<string>                                                                                       $helperIds
     * @param array{description: string|null, weight_kg: float|null, items: list<array{name: string, qty: int}>} $load
     */
    private function __construct(
        public string $id,
        public string $vehicleId,
        public ?string $driverId,
        public array $helperIds,
        public ?string $destination,
        public ?string $routeRef,
        public array $load,
        public ?int $odometerOutKm,
        public ?int $odometerInKm,
        public ?int $kmTraveled,
        public string $status,
        public string $departedAt,
        public ?string $returnedAt,
        public ?string $note,
    ) {}

    public static function fromDomain(Departure $departure): self
    {
        $returnedAt = $departure->returnedAt();

        return new self(
            $departure->id()->toString(),
            $departure->vehicleId(),
            $departure->driverId(),
            $departure->helperIds(),
            $departure->destination(),
            $departure->routeRef(),
            $departure->load()->toArray(),
            $departure->odometerOut()?->km,
            $departure->odometerIn()?->km,
            $departure->kmTraveled(),
            $departure->status()->value,
            $departure->departedAt()->format(DateTimeImmutable::ATOM),
            $returnedAt?->format(DateTimeImmutable::ATOM),
            $departure->note(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, helper_ids: list<string>, destination: string|null, route_ref: string|null, load: array{description: string|null, weight_kg: float|null, items: list<array{name: string, qty: int}>}, odometer_out_km: int|null, odometer_in_km: int|null, km_traveled: int|null, status: string, departed_at: string, returned_at: string|null, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'driver_id' => $this->driverId,
            'helper_ids' => $this->helperIds,
            'destination' => $this->destination,
            'route_ref' => $this->routeRef,
            'load' => $this->load,
            'odometer_out_km' => $this->odometerOutKm,
            'odometer_in_km' => $this->odometerInKm,
            'km_traveled' => $this->kmTraveled,
            'status' => $this->status,
            'departed_at' => $this->departedAt,
            'returned_at' => $this->returnedAt,
            'note' => $this->note,
        ];
    }
}
