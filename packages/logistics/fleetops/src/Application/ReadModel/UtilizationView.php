<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

/**
 * Proyección de utilización de un vehículo en un rango: cuántas salidas tuvo, cuántos km recorrió, cuántas
 * horas estuvo en ruta (salidas cerradas) y cuál fue su última lectura de odómetro. Primitivos para API/admin.
 *
 * Forma ESTABLE (auditoría #24, schema OpenAPI `VehicleUtilization`): solo se agregan claves, nunca se
 * renombran. `vehicle_plate` es aditiva (la app ya no adivina `plate`/`vehicle_plate`).
 */
final readonly class UtilizationView
{
    public function __construct(
        public string $vehicleId,
        public string $from,
        public string $to,
        public int $departuresCount,
        public int $kmTraveled,
        public float $hoursOnRoad,
        public ?int $lastOdometerKm,
        public ?string $lastOdometerReadAt,
        public ?string $vehiclePlate = null,
    ) {}

    /**
     * @return array{vehicle_id: string, vehicle_plate: string|null, from: string, to: string, departures_count: int, km_traveled: int, hours_on_road: float, last_odometer_km: int|null, last_odometer_read_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'vehicle_id' => $this->vehicleId,
            'vehicle_plate' => $this->vehiclePlate,
            'from' => $this->from,
            'to' => $this->to,
            'departures_count' => $this->departuresCount,
            'km_traveled' => $this->kmTraveled,
            'hours_on_road' => $this->hoursOnRoad,
            'last_odometer_km' => $this->lastOdometerKm,
            'last_odometer_read_at' => $this->lastOdometerReadAt,
        ];
    }
}
