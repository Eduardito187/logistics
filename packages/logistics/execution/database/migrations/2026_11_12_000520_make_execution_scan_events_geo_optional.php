<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GPS opcional en los escaneos: sin señal (interiores) la app mandaba 0,0 y eso contaminaba toda auditoría
 * geográfica. Ahora lat/lng admiten NULL (posición desconocida) y se agregan la precisión del fix y la hora
 * en que el teléfono lo capturó (location:{lat,lng,accuracy_m,captured_at} del POD).
 *
 * Aditiva e idempotente: DROP NOT NULL es no-op si ya es nullable; las columnas nuevas se agregan solo si
 * faltan. No toca datos (los 0,0 históricos quedan como están: no se reescribe evidencia).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE execution_scan_events ALTER COLUMN lat DROP NOT NULL');
            DB::statement('ALTER TABLE execution_scan_events ALTER COLUMN lng DROP NOT NULL');
        } else {
            Schema::table('execution_scan_events', function (Blueprint $table): void {
                $table->decimal('lat', 10, 7)->nullable()->change();
                $table->decimal('lng', 10, 7)->nullable()->change();
            });
        }

        Schema::table('execution_scan_events', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_scan_events', 'accuracy_m')) {
                $table->decimal('accuracy_m', 8, 1)->nullable();
            }
            if (!Schema::hasColumn('execution_scan_events', 'location_captured_at')) {
                $table->timestampTz('location_captured_at')->nullable();
            }
        });
    }

    public function down(): void
    {
        // No se vuelve a NOT NULL: habría filas legítimas sin posición. Solo se quitan las columnas nuevas.
        Schema::table('execution_scan_events', function (Blueprint $table): void {
            foreach (['accuracy_m', 'location_captured_at'] as $column) {
                if (Schema::hasColumn('execution_scan_events', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
