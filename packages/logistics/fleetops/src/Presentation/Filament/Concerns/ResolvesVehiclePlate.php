<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Concerns;

use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Resuelve la PLACA legible de un vehículo a partir de su id (UUID de `fleet_vehicles`) para los
 * recursos Filament de FleetOps. Lectura cruzada SOLO para mostrar: nunca muta el otro contexto.
 *
 * FleetOps referencia al vehículo por su id como texto sin FK dura (contexto desacoplable), por lo que
 * no existe una relación Eloquent sobre los records de este package. Esta utilidad cachea en memoria
 * las placas ya consultadas para no repetir consultas en un mismo request (lista con placas repetidas).
 */
trait ResolvesVehiclePlate
{
    /**
     * Caché por request: vehicle_id → placa (o '—' si el vehículo no existe).
     *
     * @var array<string, string>
     */
    private static array $plateCache = [];

    /**
     * Placa legible del vehículo; '—' si el id es nulo o el vehículo ya no existe en fleet.
     */
    protected static function vehiclePlate(?string $vehicleId): string
    {
        if ($vehicleId === null || $vehicleId === '') {
            return '—';
        }

        if (!array_key_exists($vehicleId, self::$plateCache)) {
            /** @var string|null $plate */
            $plate = VehicleModel::query()->whereKey($vehicleId)->value('plate');
            self::$plateCache[$vehicleId] = $plate ?? '—';
        }

        return self::$plateCache[$vehicleId];
    }

    /**
     * Resultados de búsqueda de placas reales de `fleet_vehicles` para un Select buscable (id → placa).
     *
     * @return array<string, string>
     */
    protected static function searchVehiclePlates(string $search): array
    {
        /** @var array<string, string> $options */
        $options = VehicleModel::query()
            ->where('plate', 'ilike', "%{$search}%")
            ->orderBy('plate')
            ->limit(50)
            ->pluck('plate', 'id')
            ->all();

        return $options;
    }
}
