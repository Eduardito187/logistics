<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Varias SALIDAS del mismo camión en el mismo día: el camión vuelve, recarga y sale de nuevo. Antes un índice
 * único (vehicle_id, route_date) permitía una sola ruta por día, y la segunda salida fallaba.
 *
 * Se reemplaza por un índice único PARCIAL: una sola ruta ABIERTA (planificada, en curso o suspendida) por
 * camión y día; las rutas ya completadas no cuentan. Así cada salida tiene su propia ruta (y su propio id,
 * que es lo que pide una nueva inspección pre-salida) sin que puedan convivir dos rutas abiertas.
 *
 * Aditiva e idempotente: no toca filas. Solo PostgreSQL (los índices parciales no existen en otros motores).
 */
return new class extends Migration
{
    private const OLD_INDEX = 'execution_driver_routes_vehicle_id_route_date_unique';

    private const NEW_INDEX = 'execution_driver_routes_one_open_per_vehicle_day';

    public function up(): void
    {
        if (!Schema::hasTable('execution_driver_routes') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE execution_driver_routes DROP CONSTRAINT IF EXISTS '.self::OLD_INDEX);
        DB::statement('DROP INDEX IF EXISTS '.self::OLD_INDEX);
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS '.self::NEW_INDEX
            ." ON execution_driver_routes (vehicle_id, route_date) WHERE status IN ('PLANNED', 'IN_PROGRESS', 'SUSPENDED')",
        );
    }

    public function down(): void
    {
        // Sin vuelta atrás a propósito: reponer el índice único completo fallaría en cuanto un camión tenga
        // dos rutas el mismo día.
    }
};
