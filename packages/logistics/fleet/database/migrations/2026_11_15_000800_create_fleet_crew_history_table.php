<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rastro de las tripulaciones que dejaron de estar vigentes (reemplazadas, desplazadas o quitadas).
 *
 * `fleet_crews` guarda solo lo vigente; al quedar sin efecto, la fila se mueve acá con su misma id, el
 * motivo y la tripulación que la reemplazó. De acá sale el «camión cambiado» de la actividad del chofer.
 * Aditiva e idempotente: si la tabla ya existe no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fleet_crew_history')) {
            return;
        }

        Schema::create('fleet_crew_history', function (Blueprint $table): void {
            // Misma id que tenía la tripulación en fleet_crews (una tripulación se retira una sola vez).
            $table->uuid('id')->primary();
            $table->uuid('driver_id');
            $table->jsonb('helper_ids');
            $table->uuid('vehicle_id');
            $table->date('assembled_for');
            $table->timestampTz('shift_starts_at')->nullable();
            $table->timestampTz('shift_ends_at')->nullable();
            // Cuándo se había armado (copia del created_at original).
            $table->timestamp('crew_created_at')->nullable();
            // replaced | displaced | disbanded (ver CrewRetirementReason).
            $table->string('reason', 20);
            // Tripulación que ocupó su lugar y el camión de esa tripulación (null si solo se quitó).
            $table->uuid('replaced_by_crew_id')->nullable();
            $table->uuid('new_vehicle_id')->nullable();
            // api = operación normal; backfill = saneo de duplicados de datos viejos.
            $table->string('source', 20)->default('api');
            // Momento del cambio, en hora de pared de la app (igual que created_at).
            $table->timestamp('retired_at');

            $table->index(['driver_id', 'assembled_for'], 'fleet_crew_history_driver_date_index');
            $table->index(['vehicle_id', 'assembled_for'], 'fleet_crew_history_vehicle_date_index');
        });
    }

    public function down(): void
    {
        // Sin reversa: es historial. Borrarlo perdería el rastro de los cambios de camión.
    }
};
