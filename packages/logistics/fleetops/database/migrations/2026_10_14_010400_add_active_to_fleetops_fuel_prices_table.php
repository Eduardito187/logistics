<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la bandera `active` a los precios de combustible. Aditiva: no toca la migración original.
 *
 * Un precio puede activarse/desactivarse desde el admin sin borrarlo del histórico; arranca activo por
 * defecto para no romper las filas ya existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleetops_fuel_prices', function (Blueprint $table): void {
            $table->boolean('active')->default(true)->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('fleetops_fuel_prices', function (Blueprint $table): void {
            $table->dropColumn('active');
        });
    }
};
