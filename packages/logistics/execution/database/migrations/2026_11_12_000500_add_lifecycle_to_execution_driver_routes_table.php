<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciclo de vida de la ruta del día: inicio y cierre reales informados por el chofer (POST
 * /app/vehicles/{id}/route/start|close), con el odómetro de salida y de cierre. ADITIVA, nullable e
 * idempotente (cada columna se agrega solo si falta): las rutas ya armadas siguen válidas.
 */
return new class extends Migration
{
    private const array COLUMNS = ['started_at', 'closed_at', 'start_odometer_km', 'close_odometer_km'];

    public function up(): void
    {
        Schema::table('execution_driver_routes', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_driver_routes', 'started_at')) {
                $table->timestampTz('started_at')->nullable();
            }
            if (!Schema::hasColumn('execution_driver_routes', 'closed_at')) {
                $table->timestampTz('closed_at')->nullable();
            }
            if (!Schema::hasColumn('execution_driver_routes', 'start_odometer_km')) {
                $table->decimal('start_odometer_km', 12, 1)->nullable();
            }
            if (!Schema::hasColumn('execution_driver_routes', 'close_odometer_km')) {
                $table->decimal('close_odometer_km', 12, 1)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_driver_routes', function (Blueprint $table): void {
            foreach (self::COLUMNS as $column) {
                if (Schema::hasColumn('execution_driver_routes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
