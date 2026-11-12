<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posición en vivo enriquecida (auditoría #17 y alertas sostenidas):
 *
 *  - `driver_id`: chofer que reportó la última lectura (para mostrar su nombre en la flota en vivo).
 *  - `speeding_since` / `off_route_since`: desde cuándo se sostiene el exceso de velocidad / el desvío de
 *    ruta. Es el estado mínimo para alertar "por encima de X durante N segundos" entre lotes distintos
 *    (una fila por vehículo, sin tabla nueva).
 *  - índice en `updated_at`: el polling incremental `?since=` filtra por la hora del servidor en que la
 *    fila cambió (no por la hora GPS, que en lotes offline llega atrasada).
 *
 * Aditiva e idempotente (hasColumn por columna). Nunca destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telemetry_vehicle_positions')) {
            return;
        }

        Schema::table('telemetry_vehicle_positions', function (Blueprint $table): void {
            if (!Schema::hasColumn('telemetry_vehicle_positions', 'driver_id')) {
                $table->string('driver_id')->nullable();
            }
            if (!Schema::hasColumn('telemetry_vehicle_positions', 'speeding_since')) {
                $table->timestampTz('speeding_since')->nullable();
            }
            if (!Schema::hasColumn('telemetry_vehicle_positions', 'off_route_since')) {
                $table->timestampTz('off_route_since')->nullable();
            }
        });

        if (!Schema::hasIndex('telemetry_vehicle_positions', 'telemetry_vehicle_positions_updated_at_index')) {
            Schema::table('telemetry_vehicle_positions', function (Blueprint $table): void {
                $table->index('updated_at', 'telemetry_vehicle_positions_updated_at_index');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('telemetry_vehicle_positions')) {
            return;
        }

        if (Schema::hasIndex('telemetry_vehicle_positions', 'telemetry_vehicle_positions_updated_at_index')) {
            Schema::table('telemetry_vehicle_positions', function (Blueprint $table): void {
                $table->dropIndex('telemetry_vehicle_positions_updated_at_index');
            });
        }

        foreach (['driver_id', 'speeding_since', 'off_route_since'] as $column) {
            if (Schema::hasColumn('telemetry_vehicle_positions', $column)) {
                Schema::table('telemetry_vehicle_positions', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
