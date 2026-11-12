<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índice por `route_date` en las rutas del chofer: los KPIs diarios barren la flota entera por fecha y el
 * único índice que cubría la columna era el único (vehicle_id, route_date), que no sirve sin vehicle_id
 * como prefijo. ADITIVA e idempotente (IF NOT EXISTS, y solo si la tabla existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('execution_driver_routes')) {
            return;
        }

        DB::statement('CREATE INDEX IF NOT EXISTS execution_driver_routes_route_date_index ON execution_driver_routes(route_date)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS execution_driver_routes_route_date_index');
    }
};
