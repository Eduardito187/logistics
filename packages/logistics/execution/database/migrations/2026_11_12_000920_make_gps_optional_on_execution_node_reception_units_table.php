<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * GPS opcional en los productos recibidos en un nodo (aditivo e idempotente).
 *
 * En interiores el teléfono puede no tener señal: lat/lng pasan a aceptar NULL para no guardar 0,0 como
 * si fuera una posición real. Se agregan la precisión del fix (metros) y el instante en que se capturó.
 * DROP NOT NULL es idempotente en PostgreSQL y no toca filas existentes.
 */
return new class extends Migration
{
    private const string TABLE = 'execution_node_reception_units';

    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN lat DROP NOT NULL');
            DB::statement('ALTER TABLE '.self::TABLE.' ALTER COLUMN lng DROP NOT NULL');
        }

        if (!Schema::hasColumn(self::TABLE, 'accuracy_m')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->decimal('accuracy_m', 8, 2)->nullable();
            });
        }

        if (!Schema::hasColumn(self::TABLE, 'location_captured_at')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->timestampTz('location_captured_at')->nullable();
            });
        }
    }

    /**
     * No se vuelve a exigir NOT NULL en lat/lng: habría que inventar posiciones para las filas sin GPS
     * (justo lo que se quiere evitar). Solo se quitan las columnas nuevas.
     */
    public function down(): void
    {
        foreach (['accuracy_m', 'location_captured_at'] as $column) {
            if (Schema::hasColumn(self::TABLE, $column)) {
                Schema::table(self::TABLE, function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
