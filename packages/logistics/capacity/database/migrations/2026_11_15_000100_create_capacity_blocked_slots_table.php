<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Franjas HORARIAS bloqueadas de despacho. A diferencia de dispatch_blackout_days (que bloquea el día
 * entero), acá se bloquea UNA franja (p. ej. "09:00-13:00") de un día sin tocar el resto. Una franja se
 * puede bloquear aunque no haya un cupo definido para ella, por eso NO se reusa capacity_buckets.
 *
 * Un bloqueo puede ser GLOBAL (municipality_id NULL: aplica a toda la red) o POR MUNICIPIO
 * (municipality_id poblado: solo ese municipio). La clave natural es (slot_date, time_slot,
 * municipality_id): un bloqueo por día, franja y municipio (o un único global por día y franja).
 *
 * Aditiva e idempotente: se crea solo si no existe, y el índice parcial de PostgreSQL con IF NOT EXISTS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('capacity_blocked_slots')) {
            return;
        }

        Schema::create('capacity_blocked_slots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('slot_date')->index();
            // Franja normalizada 'HH:MM-HH:MM' (el VO TimeSlot la valida antes de persistir).
            $table->string('time_slot', 32);
            // NULL = bloqueo global (toda la red); poblado = solo ese municipio (UUID de coverage).
            $table->uuid('municipality_id')->nullable()->index();
            $table->string('reason');
            // Id de quien bloqueó: el identificador del usuario autenticado (users.id es bigint), se guarda
            // como string por consistencia con las demás columnas de auditoría de capacity.
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            // Clave natural: un bloqueo por fecha, franja y municipio. Por los NULL distintos de PostgreSQL,
            // la unicidad del bloqueo global (municipio NULL) se refuerza con un índice parcial más abajo.
            $table->unique(['slot_date', 'time_slot', 'municipality_id'], 'capacity_blocked_slots_natural_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Un único bloqueo GLOBAL por fecha y franja (municipality_id NULL): el unique compuesto no lo
            // impide porque PostgreSQL trata los NULL como distintos.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS capacity_blocked_slots_global_natural_key '
                .'ON capacity_blocked_slots (slot_date, time_slot) WHERE municipality_id IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS capacity_blocked_slots_global_natural_key');
        }

        Schema::dropIfExists('capacity_blocked_slots');
    }
};
