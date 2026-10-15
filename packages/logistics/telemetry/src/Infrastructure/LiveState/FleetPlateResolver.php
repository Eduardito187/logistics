<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\LiveState;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Resuelve la PLACA legible de un vehículo a partir de su id (UUID de `fleet_vehicles`) para enriquecer
 * el fan-out en vivo de telemetría.
 *
 * Lectura CROSS-CONTEXT read-only (patrón api-readmodel-enrichment): se lee la tabla del contexto fleet
 * con `DB::table` — sin dependencia composer ni FK dura entre contextos, coherente con la meta de que
 * cada contexto sea extraíble a microservicio. La resolución es EN LOTE (`whereIn`) y memoizada por
 * proceso para evitar el N+1 cuando se difunden muchos vehículos. Degrada con gracia: si la tabla no
 * existe o falla, devuelve null (el broadcast viaja sin placa, no rompe).
 */
final class FleetPlateResolver
{
    /** @var array<string, string|null> Mapa memoizado id→placa (null = buscado y no encontrado). */
    private array $cache = [];

    /**
     * Placa de un vehículo por su id, o null si no existe / no se pudo resolver.
     */
    public function label(string $vehicleId): ?string
    {
        return $this->labels([$vehicleId])[$vehicleId] ?? null;
    }

    /**
     * Placas de un lote de vehículos (id→placa|null), resolviendo en una sola query lo aún no memoizado.
     *
     * @param  list<string>               $vehicleIds
     * @return array<string, string|null>
     */
    public function labels(array $vehicleIds): array
    {
        $missing = array_values(array_filter(
            array_unique($vehicleIds),
            fn (string $id): bool => !array_key_exists($id, $this->cache),
        ));

        if ($missing !== []) {
            try {
                /** @var array<string, string> $rows */
                $rows = DB::table('fleet_vehicles')
                    ->whereIn('id', $missing)
                    ->pluck('plate', 'id')
                    ->all();

                foreach ($missing as $id) {
                    $this->cache[$id] = $rows[$id] ?? null;
                }
            } catch (Throwable) {
                // Degradación con gracia: sin placa, el fan-out sigue con el UUID.
                foreach ($missing as $id) {
                    $this->cache[$id] ??= null;
                }
            }
        }

        $result = [];
        foreach ($vehicleIds as $id) {
            $result[$id] = $this->cache[$id] ?? null;
        }

        return $result;
    }
}
