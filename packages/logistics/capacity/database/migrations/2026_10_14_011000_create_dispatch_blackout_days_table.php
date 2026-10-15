<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Días bloqueados de despacho (feriados u otros motivos). El calendario de 7 días se genera siempre,
 * pero estos días se SALTAN al activar cupos y se RECHAZAN al consumir capacidad.
 *
 * Un bloqueo puede ser GLOBAL (municipality_id NULL: aplica a toda la red) o POR MUNICIPIO
 * (municipality_id poblado: solo ese municipio). La clave natural es (blackout_date, municipality_id):
 * un bloqueo por día y municipio (o un único global por día).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dispatch_blackout_days', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('blackout_date')->index();
            // NULL = bloqueo global (toda la red); poblado = solo ese municipio (UUID de coverage).
            $table->uuid('municipality_id')->nullable()->index();
            $table->string('reason');
            // Id de quien bloqueó: NO es un uuid de dominio sino el identificador del usuario Filament
            // autenticado (users.id es bigint autoincrement), así que se guarda como string para no reventar
            // al bloquear un día desde el admin. Consistente con las demás columnas de auditoría de capacity.
            $table->string('created_by', 64)->nullable();
            $table->timestamps();

            // Clave natural: un bloqueo por fecha y municipio. Por los NULL distintos de PostgreSQL, la
            // unicidad real del bloqueo global se refuerza con un índice parcial más abajo.
            $table->unique(['blackout_date', 'municipality_id'], 'dispatch_blackout_days_natural_key');
        });

        if (DB::getDriverName() === 'pgsql') {
            // Un único bloqueo GLOBAL por fecha (municipality_id NULL): el unique compuesto no lo impide
            // porque PostgreSQL trata los NULL como distintos.
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS dispatch_blackout_days_global_natural_key '
                .'ON dispatch_blackout_days (blackout_date) WHERE municipality_id IS NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS dispatch_blackout_days_global_natural_key');
        }

        Schema::dropIfExists('dispatch_blackout_days');
    }
};
