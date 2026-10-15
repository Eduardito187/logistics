<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Salidas/despachos de la flota propia: quién salió, con qué carga y odómetro, y cuándo retornó. El
 * vehículo se referencia por su id como texto (UUID de fleet) sin FK dura: contexto desacoplable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_departures', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_id')->index();
            $table->string('driver_id')->nullable();
            // Ayudantes como lista de ids (strings) en JSON.
            $table->jsonb('helper_ids');
            $table->string('destination')->nullable();
            $table->string('route_ref')->nullable();
            // Carga: descripción libre, peso en kilos y detalle opcional de ítems (JSON).
            $table->string('load_description')->nullable();
            $table->decimal('load_weight_kg', 10, 2)->nullable();
            $table->jsonb('load_items');
            // Odómetro en km a la salida y al regreso; la diferencia son los km recorridos.
            $table->integer('odometer_out_km')->nullable();
            $table->integer('odometer_in_km')->nullable();
            // Estado de la salida: 'out' (en ruta) | 'returned' (cerrada).
            $table->string('status')->index();
            $table->timestampTz('departed_at')->index();
            $table->timestampTz('returned_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();

            // Índice compuesto: salidas de un vehículo por fecha (traza operativa y utilización).
            $table->index(['vehicle_id', 'departed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_departures');
    }
};
