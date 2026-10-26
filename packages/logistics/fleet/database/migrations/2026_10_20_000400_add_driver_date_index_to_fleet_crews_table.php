<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índice compuesto para la consulta caliente de la app del chofer (`GET /app/fleet/my-crew`):
 * `where driver_id = ? and assembled_for = ? order by shift_starts_at desc limit 1`. Con solo el índice
 * por driver_id, Postgres recorre todas las tripulaciones históricas del chofer (una por día, crece
 * sin tope); con este resuelve la fila exacta por índice, ya ordenada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_crews', function (Blueprint $table): void {
            $table->index(['driver_id', 'assembled_for', 'shift_starts_at'], 'fleet_crews_driver_date_shift_index');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_crews', function (Blueprint $table): void {
            $table->dropIndex('fleet_crews_driver_date_shift_index');
        });
    }
};
