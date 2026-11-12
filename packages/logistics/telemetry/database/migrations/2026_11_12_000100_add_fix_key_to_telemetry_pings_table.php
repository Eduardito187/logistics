<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotencia por lectura GPS en la ingesta por lote (auditoría #12).
 *
 * `fix_key` es la clave estable de una lectura: el `fix_id` que manda el cliente o, si no lo manda,
 * `device_id@recorded_at`. Con el índice único (vehicle_id, recorded_at, fix_key) un reintento del mismo
 * lote no duplica pings (ni infla la distancia de los trips): la ingesta inserta con ON CONFLICT DO NOTHING.
 *
 * La tabla está particionada por `recorded_at` (C6): Postgres exige que un índice único incluya la clave
 * de partición, por eso `recorded_at` forma parte del índice. Las filas viejas quedan con fix_key NULL
 * (los NULL no chocan entre sí), así que el índice no rompe datos existentes.
 *
 * Aditiva e idempotente (hasColumn + IF NOT EXISTS). Nunca destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('telemetry_pings')) {
            return;
        }

        if (!Schema::hasColumn('telemetry_pings', 'fix_key')) {
            Schema::table('telemetry_pings', function (Blueprint $table): void {
                $table->string('fix_key', 191)->nullable();
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE UNIQUE INDEX IF NOT EXISTS telemetry_pings_vehicle_recorded_fix_unique '
                .'ON telemetry_pings (vehicle_id, recorded_at, fix_key)',
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS telemetry_pings_vehicle_recorded_fix_unique');
        }

        if (Schema::hasTable('telemetry_pings') && Schema::hasColumn('telemetry_pings', 'fix_key')) {
            Schema::table('telemetry_pings', function (Blueprint $table): void {
                $table->dropColumn('fix_key');
            });
        }
    }
};
