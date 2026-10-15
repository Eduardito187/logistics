<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Support;

use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Resuelve la PLACA de un vehículo a partir de su id (UUID de `fleet_vehicles`) para la capa Filament
 * de telemetría.
 *
 * Es una lectura cross-context de SOLO presentación (nunca para mutar): los records de telemetry guardan
 * `vehicle_id` como UUID sin FK dura, y aquí se enriquece con la placa legible leyendo del contexto fleet
 * (patrón api-readmodel-enrichment, mismo que fleetops). El mapa id→placa se carga una vez por request y se
 * memoiza para evitar el N+1 al pintar tablas (regla: ningún UUID visible en el admin).
 */
final class VehiclePlates
{
    /** @var array<string, string>|null Mapa memoizado id→placa de todos los vehículos. */
    private static ?array $map = null;

    /**
     * Mapa id→placa de todos los vehículos de la flota (memoizado por request).
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        if (self::$map === null) {
            /** @var array<string, string> $map */
            $map = VehicleModel::query()
                ->orderBy('plate')
                ->pluck('plate', 'id')
                ->all();
            self::$map = $map;
        }

        return self::$map;
    }

    /**
     * Placa de un vehículo por su id, o null si no existe.
     */
    public static function label(string $vehicleId): ?string
    {
        return self::options()[$vehicleId] ?? null;
    }
}
