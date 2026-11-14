<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Liga cada lectura de odómetro de salida/llegada a la RUTA del chofer a la que pertenece. Así el cierre de
 * la ruta deja de inferirse por la hora y el backend puede rechazar una segunda llegada de la misma ruta.
 * Aditiva e idempotente: columna nullable (las lecturas viejas y las manuales quedan sin ruta).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('fleetops_odometer_readings') || Schema::hasColumn('fleetops_odometer_readings', 'route_id')) {
            return;
        }

        Schema::table('fleetops_odometer_readings', function (Blueprint $table): void {
            $table->string('route_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('fleetops_odometer_readings', 'route_id')) {
            Schema::table('fleetops_odometer_readings', function (Blueprint $table): void {
                $table->dropColumn('route_id');
            });
        }
    }
};
