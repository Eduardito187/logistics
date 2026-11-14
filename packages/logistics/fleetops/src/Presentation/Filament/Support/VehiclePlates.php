<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Filament\Support;

use Illuminate\Support\Facades\Cache;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Resuelve la PLACA de un vehículo a partir de su id (UUID de `fleet_vehicles`) para la capa Filament.
 *
 * Es una lectura cross-context de SOLO presentación (nunca para mutar): los records de fleetops guardan
 * `vehicle_id` como UUID sin FK dura, y aquí se enriquece con la placa legible leyendo del contexto fleet
 * (patrón api-readmodel-enrichment). El mapa id→placa se cachea en Valkey con TTL corto para evitar el
 * N+1 al pintar tablas. Antes se memoizaba en una propiedad estática ("por request"), pero bajo Octane el
 * worker vive entre requests y esa estática serviría placas viejas indefinidamente; el caché con TTL se
 * refresca solo. La clave se comparte con telemetry (misma tabla, misma forma).
 */
final class VehiclePlates
{
    /** Vida del caché del mapa (segundos); las placas casi no cambian, 60s de staleness es aceptable. */
    private const CACHE_TTL_SECONDS = 60;

    /**
     * Mapa id→placa de todos los vehículos de la flota (cacheado con TTL corto).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        return Cache::remember(
            'admin:vehicle-plates',
            self::CACHE_TTL_SECONDS,
            static function (): array {
                /** @var array<string, string> $map */
                $map = VehicleModel::query()
                    ->orderBy('plate')
                    ->pluck('plate', 'id')
                    ->all();

                return $map;
            },
        );
    }

    /**
     * Placa de un vehículo por su id, o null si no existe.
     */
    public static function label(string $vehicleId): ?string
    {
        return self::options()[$vehicleId] ?? null;
    }

    /**
     * Resultados de búsqueda id→placa para un select buscable (coincidencia por placa).
     *
     * @return array<string, string>
     */
    public static function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return self::options();
        }

        $results = [];
        foreach (self::options() as $id => $plate) {
            if (stripos($plate, $term) !== false) {
                $results[$id] = $plate;
            }
        }

        return $results;
    }
}
