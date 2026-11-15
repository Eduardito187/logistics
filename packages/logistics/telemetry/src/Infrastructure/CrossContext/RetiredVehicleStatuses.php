<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\CrossContext;

/**
 * Estados del vehículo en el contexto fleet (`fleet_vehicles.status`) que significan "dado de baja": el
 * camión deja de ser parte de la flota en vivo. Hoy fleet tiene uno solo, `inactive` (no hay borrado ni
 * soft delete de vehículos). Vive en configuración (`logistics-telemetry.live.retired_statuses`) para no
 * acoplar telemetría al enum de fleet: se compara el texto, sin importar mayúsculas.
 */
final class RetiredVehicleStatuses
{
    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $statuses = [];
        foreach ((array) config('logistics-telemetry.live.retired_statuses', ['inactive']) as $status) {
            if (is_string($status) && trim($status) !== '') {
                $statuses[] = strtolower(trim($status));
            }
        }

        return array_values(array_unique($statuses));
    }

    /** ¿Ese estado de fleet es una baja? */
    public static function includes(mixed $status): bool
    {
        return is_string($status) && in_array(strtolower(trim($status)), self::all(), true);
    }
}
