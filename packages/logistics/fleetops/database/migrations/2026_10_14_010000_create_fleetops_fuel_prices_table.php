<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de precios de combustible por tipo, fechados. Cada cambio de precio es una fila nueva; el precio
 * vigente a una fecha es el más reciente con `effective_from <= fecha`. El dinero va en centavos (entero).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_fuel_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('fuel_type')->index();
            // Precio por litro en centavos (entero) para evitar errores de redondeo.
            $table->integer('price_cents');
            $table->string('currency');
            $table->date('effective_from')->index();
            // Origen del precio (p. ej. ANH, carga manual), opcional.
            $table->string('source')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_fuel_prices');
    }
};
