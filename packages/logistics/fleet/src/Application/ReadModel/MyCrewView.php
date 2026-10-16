<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\Model\Driver;
use Logistics\Fleet\Domain\Model\Helper;
use Logistics\Fleet\Domain\Model\Vehicle;

/**
 * Proyección de lectura de la asignación del día de un chofer: el vehículo (camión) y la tripulación
 * (chofer + ayudantes) armados por el admin para una fecha. Primitivos: no expone objetos de dominio.
 *
 * El vehículo, el chofer y cada ayudante se resuelven por su id contra sus repositorios para mostrar
 * datos legibles (placa, nombre). Si alguno no se encuentra (dato maestro faltante), se deja en null:
 * la tripulación sigue siendo la que armó el admin.
 */
final readonly class MyCrewView
{
    /**
     * @param array{id: string, plate: string|null, capacity_kg: int|null, capacity_m3: float|null, status: string|null}|null $vehicle
     * @param array{id: string, name: string|null, license_number: string|null}                                               $driver
     * @param list<array{id: string, name: string|null}>                                                                      $helpers
     * @param array{starts_at: string, ends_at: string}|null                                                                  $shift
     */
    private function __construct(
        public string $crewId,
        public string $date,
        public ?array $vehicle,
        public array $driver,
        public array $helpers,
        public ?array $shift,
    ) {}

    /**
     * Compone la vista a partir de la tripulación y los datos maestros ya resueltos.
     *
     * @param array<string, Helper> $helpersById ayudantes indexados por su id (para nombre legible)
     */
    public static function fromDomain(
        Crew $crew,
        ?Vehicle $vehicle,
        ?Driver $driver,
        array $helpersById,
    ): self {
        $helpers = [];
        foreach ($crew->helperIds() as $helperId) {
            $helper = $helpersById[$helperId] ?? null;
            $helpers[] = [
                'id' => $helperId,
                'name' => $helper?->name(),
            ];
        }

        return new self(
            $crew->id()->toString(),
            $crew->assembledFor()->format('Y-m-d'),
            $vehicle instanceof Vehicle ? [
                'id' => $vehicle->id()->toString(),
                'plate' => $vehicle->plate()->value,
                'capacity_kg' => $vehicle->capacity()->kilograms,
                'capacity_m3' => $vehicle->capacity()->cubicMeters,
                'status' => $vehicle->status()->value,
            ] : [
                'id' => $crew->vehicleId()->toString(),
                'plate' => null,
                'capacity_kg' => null,
                'capacity_m3' => null,
                'status' => null,
            ],
            [
                'id' => $crew->driverId()->toString(),
                'name' => $driver?->name(),
                'license_number' => $driver?->license()->value,
            ],
            $helpers,
            $crew->shift()?->toArray(),
        );
    }

    /**
     * @return array{
     *     crew_id: string, date: string,
     *     vehicle: array{id: string, plate: string|null, capacity_kg: int|null, capacity_m3: float|null, status: string|null}|null,
     *     driver: array{id: string, name: string|null, license_number: string|null},
     *     helpers: list<array{id: string, name: string|null}>,
     *     shift: array{starts_at: string, ends_at: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'crew_id' => $this->crewId,
            'date' => $this->date,
            'vehicle' => $this->vehicle,
            'driver' => $this->driver,
            'helpers' => $this->helpers,
            'shift' => $this->shift,
        ];
    }
}
