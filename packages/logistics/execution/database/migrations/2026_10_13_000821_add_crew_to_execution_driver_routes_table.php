<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece la ruta del día del camión con la tripulación que la condujo: chofer y ayudantes. Todo
 * ADITIVO y nullable: las rutas ya armadas siguen válidas sin estos datos.
 *
 * Se pueblan (best-effort) al armar la ruta a partir del crew del vehículo+fecha; si quedan vacíos, el
 * historial operativo del chofer cae al join por (vehicle_id, route_date == assembled_for) contra
 * `fleet_crews`. El `driver_id` indexado permite el camino rápido del historial cuando está poblado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_driver_routes', function (Blueprint $table): void {
            // Chofer que condujo la ruta (UUID v7 del chofer en Fleet). Indexado para el historial.
            $table->uuid('driver_id')->nullable()->index()->after('vehicle_id');
            // Ayudantes de la tripulación que acompañaron la ruta: lista de UUIDs de ayudantes.
            $table->jsonb('helper_ids')->nullable()->after('driver_id');
        });
    }

    public function down(): void
    {
        Schema::table('execution_driver_routes', function (Blueprint $table): void {
            $table->dropColumn(['driver_id', 'helper_ids']);
        });
    }
};
