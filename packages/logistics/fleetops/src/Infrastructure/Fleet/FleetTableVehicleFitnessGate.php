<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Fleet;

use DateTimeImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Logistics\FleetOps\Domain\Contract\VehicleFitnessGate;

/**
 * Lee la aptitud del vehículo de `fleet_vehicles.unfit_since/unfit_reason` (lectura cruzada por valor
 * dentro del monolito; fleetops no depende del package fleet, igual que TelemetryDistanceReader).
 *
 * No apto desde `unfit_since` (inclusive) en adelante. Si la tabla/columna no existe en este entorno
 * (migración de fleet sin correr), degrada a «apto» para no frenar la operación por un desfase de esquema.
 */
final readonly class FleetTableVehicleFitnessGate implements VehicleFitnessGate
{
    public function unfitReason(string $vehicleId, DateTimeImmutable $on): ?string
    {
        // Se verifica el esquema ANTES de consultar: en Postgres un error de SQL dentro de la transacción
        // del comando la aborta entera, así que no se puede «probar y capturar».
        if (!self::schemaReady()) {
            return null;
        }
        // `fleet_vehicles.id` es uuid: un id con otro formato abortaría la transacción en Postgres, y de
        // todos modos no puede ser un vehículo de flota registrado.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $vehicleId) !== 1) {
            return null;
        }

        $row = DB::table('fleet_vehicles')
            ->where('id', $vehicleId)
            ->whereNotNull('unfit_since')
            ->where('unfit_since', '<=', $on->format('Y-m-d'))
            ->first(['plate', 'unfit_since', 'unfit_reason']);

        if ($row === null) {
            return null;
        }

        $plate = is_string($row->plate ?? null) ? $row->plate : $vehicleId;
        $since = is_string($row->unfit_since ?? null) ? substr($row->unfit_since, 0, 10) : $on->format('Y-m-d');
        $reason = is_string($row->unfit_reason ?? null) && $row->unfit_reason !== ''
            ? $row->unfit_reason
            : 'falla crítica en el checklist pre-salida';

        return "El vehículo {$plate} no está apto para despachar desde {$since}: {$reason}. "
            .'Un supervisor debe habilitarlo tras la reparación.';
    }

    /**
     * ¿Existe `fleet_vehicles.unfit_since`? Se resuelve una vez por proceso (el esquema no cambia en caliente).
     */
    private static function schemaReady(): bool
    {
        // Solo se memoriza el «sí»: si falta la columna se vuelve a mirar (puede migrarse sin reiniciar).
        static $ready = false;

        if (!$ready) {
            try {
                $ready = Schema::hasColumn('fleet_vehicles', 'unfit_since');
            } catch (QueryException) {
                $ready = false;
            }
        }

        return $ready;
    }
}
