<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Service;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Lector read-only de los km recorridos por vehículo según telemetría (`telemetry_trips.distance_m`).
 *
 * Es una lectura CRUZADA entre contextos del monorepo (no una dependencia composer): fleetops no depende
 * de telemetry. Degrada a vacío/0 si la tabla no existe en este entorno (patrón del contexto, igual que
 * GetVehicleCostSummaryHandler). Lee EN LOTE para evitar N+1 cuando se calculan muchos vehículos.
 */
final readonly class TelemetryDistanceReader
{
    /**
     * Km recorridos por vehículo en el rango (id → km). Vehículos sin viajes no aparecen en el mapa.
     *
     * @return array<string, int>
     */
    public function kmByVehicle(string $from, string $to): array
    {
        try {
            /** @var array<string, int> $rows */
            $rows = DB::table('telemetry_trips')
                ->selectRaw('vehicle_id, SUM(distance_m) as meters')
                ->whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])
                ->groupBy('vehicle_id')
                ->pluck('meters', 'vehicle_id')
                ->map(static fn ($meters): int => (int) round(((float) $meters) / 1000))
                ->all();

            return $rows;
        } catch (Throwable) {
            return [];
        }
    }
}
