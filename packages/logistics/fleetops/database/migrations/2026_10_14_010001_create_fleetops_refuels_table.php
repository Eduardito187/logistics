<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de cargas de combustible de la flota propia: litros, precio por litro, costo total (en centavos),
 * odómetro al cargar y marca de tanque lleno (base del análisis de consumo entre llenados consecutivos).
 *
 * El vehículo y el conductor se guardan como ids en texto (UUID de `fleet`), sin FK dura: el contexto es
 * desacoplable a un servicio independiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_refuels', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->uuid('driver_id')->nullable();
            $table->string('fuel_type');
            $table->decimal('liters', 8, 2);
            // Precio por litro y costo total en centavos (entero) para evitar errores de redondeo.
            $table->integer('price_per_liter_cents');
            $table->string('currency');
            $table->integer('total_cost_cents');
            $table->integer('odometer_km')->nullable();
            $table->string('station')->nullable();
            $table->boolean('full_tank')->default(false);
            $table->timestampTz('refueled_at')->index();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_refuels');
    }
};
