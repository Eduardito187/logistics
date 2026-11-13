<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata la inspección pre-salida a una ruta/viaje: un camión que sale a dos entregas se inspecciona dos
 * veces. `route_id` es un UUID en texto, OPCIONAL (las inspecciones históricas y las "por día" quedan
 * en null) e indexado para poder saber si una ruta ya tiene su checklist filtrando el listado.
 *
 * ADITIVO e idempotente. Se guarda como UUID para NO acoplar fleet a execution (el formato lo fija el
 * VO RouteId de execution: UUID v7).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fleet_pre_trip_checklists', 'route_id')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->uuid('route_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('fleet_pre_trip_checklists', 'route_id')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->dropColumn('route_id');
            });
        }
    }
};
