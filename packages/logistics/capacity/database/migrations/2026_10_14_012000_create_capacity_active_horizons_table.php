<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Horizonte activo de cupos por municipio: hasta qué fecha hay cupos activados ("cupos activos hasta
 * <fecha>"). Lo mueve hacia adelante la activación por rango (ActivateCapacityWindow) y lo usa el
 * enforcement del consumo para RECHAZAR imputar capacidad en una fecha fuera del horizonte activo.
 *
 * Un registro GLOBAL (municipality_id NULL) para la activación de toda la red, y uno por municipio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('capacity_active_horizons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // NULL = horizonte global (toda la red); poblado = solo ese municipio (UUID de coverage).
            $table->uuid('municipality_id')->nullable();
            $table->date('active_until');
            $table->timestamps();

            $table->unique('municipality_id', 'capacity_active_horizons_municipality_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Un único horizonte GLOBAL (municipality_id NULL): el unique de arriba no lo garantiza
            // porque PostgreSQL trata los NULL como distintos.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS capacity_active_horizons_global_key '
                .'ON capacity_active_horizons ((municipality_id IS NULL)) WHERE municipality_id IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS capacity_active_horizons_global_key');
        }

        Schema::dropIfExists('capacity_active_horizons');
    }
};
