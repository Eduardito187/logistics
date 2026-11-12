<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aptitud para despachar del vehículo (auditoría #27): si un checklist pre-salida reporta un ítem CRÍTICO
 * fallido, el vehículo queda NO APTO desde esa fecha hasta que un supervisor lo habilite.
 *
 * ADITIVO e idempotente: columnas nullable (null = apto), cada una solo si no existe. Los vehículos ya
 * registrados quedan aptos. `unfit_since` va indexada porque la consultan la asignación y las salidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'unfit_since' => static fn (Blueprint $table) => $table->date('unfit_since')->nullable()->index(),
            'unfit_reason' => static fn (Blueprint $table) => $table->string('unfit_reason', 500)->nullable(),
            'unfit_inspection_id' => static fn (Blueprint $table) => $table->uuid('unfit_inspection_id')->nullable(),
            'unfit_items' => static fn (Blueprint $table) => $table->jsonb('unfit_items')->nullable(),
        ];

        foreach ($columns as $name => $add) {
            if (Schema::hasColumn('fleet_vehicles', $name)) {
                continue;
            }
            Schema::table('fleet_vehicles', static function (Blueprint $table) use ($add): void {
                $add($table);
            });
        }
    }

    public function down(): void
    {
        foreach (['unfit_items', 'unfit_inspection_id', 'unfit_reason', 'unfit_since'] as $name) {
            if (Schema::hasColumn('fleet_vehicles', $name)) {
                Schema::table('fleet_vehicles', static function (Blueprint $table) use ($name): void {
                    $table->dropColumn($name);
                });
            }
        }
    }
};
