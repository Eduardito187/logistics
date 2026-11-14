<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Concerns;

use Illuminate\Support\Facades\Cache;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Resuelve la PLACA legible de un vehículo a partir de su id (UUID de `fleet_vehicles`) para los
 * recursos Filament de FleetOps. Lectura cruzada SOLO para mostrar: nunca muta el otro contexto.
 *
 * FleetOps referencia al vehículo por su id como texto sin FK dura (contexto desacoplable), por lo que
 * no existe una relación Eloquent sobre los records de este package. El mapa id→placa se cachea en
 * Valkey con TTL corto, compartiendo la clave 'admin:vehicle-plates' con VehiclePlates (misma tabla,
 * misma forma), para no repetir consultas al pintar tablas con placas repetidas.
 */
trait ResolvesVehiclePlate
{
    /** Vida del caché del mapa id→placa (segundos); las placas casi no cambian. */
    private const VEHICLE_PLATES_TTL = 60;

    /**
     * Placa legible del vehículo; '—' si el id es nulo o el vehículo ya no existe en fleet.
     */
    protected static function vehiclePlate(?string $vehicleId): string
    {
        if ($vehicleId === null || $vehicleId === '') {
            return '—';
        }

        return self::vehiclePlates()[$vehicleId] ?? '—';
    }

    /**
     * Mapa id→placa de todos los vehículos (fleet_vehicles), cacheado con TTL corto. Reusa la clave
     * 'admin:vehicle-plates' de VehiclePlates para compartir caché.
     *
     * Antes se memoizaba en una propiedad estática ("por request"), pero bajo Octane el worker vive
     * entre requests y esa estática serviría placas viejas indefinidamente además de crecer en memoria;
     * el caché con TTL se refresca solo (staleness ≤60s, aceptable para placas).
     *
     * @return array<string, string>
     */
    private static function vehiclePlates(): array
    {
        return Cache::remember(
            'admin:vehicle-plates',
            self::VEHICLE_PLATES_TTL,
            static function (): array {
                /** @var array<string, string> */
                return VehicleModel::query()
                    ->orderBy('plate')
                    ->pluck('plate', 'id')
                    ->all();
            },
        );
    }

    /**
     * Resultados de búsqueda de placas reales de `fleet_vehicles` para un Select buscable (id → placa).
     *
     * @return array<string, string>
     */
    protected static function searchVehiclePlates(string $search): array
    {
        /** @var array<string, string> */
        return VehicleModel::query()
            ->where('plate', 'ilike', "%{$search}%")
            ->orderBy('plate')
            ->limit(50)
            ->pluck('plate', 'id')
            ->all();
    }
}
