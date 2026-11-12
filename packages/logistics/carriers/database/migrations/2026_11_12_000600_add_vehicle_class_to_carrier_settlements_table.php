<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Clase de vehículo tarifada en la liquidación de transportista (trailer, truck, third_party).
 *
 * Aditiva e idempotente: columna nullable (las liquidaciones históricas quedan en null, sin backfill) y
 * solo se agrega si no existe. Sin índice: es un dato de detalle, no un filtro de listado.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('carrier_settlements', 'vehicle_class')) {
            return;
        }

        Schema::table('carrier_settlements', function (Blueprint $table): void {
            $table->string('vehicle_class')->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('carrier_settlements', 'vehicle_class')) {
            return;
        }

        Schema::table('carrier_settlements', function (Blueprint $table): void {
            $table->dropColumn('vehicle_class');
        });
    }
};
