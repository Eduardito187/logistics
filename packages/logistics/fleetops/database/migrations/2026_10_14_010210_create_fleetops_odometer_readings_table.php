<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Histórico append-only de lecturas de odómetro (km) por vehículo y fuente. Alimenta el odómetro actual,
 * el consumo y la utilización. El vehículo se referencia por su id como texto (UUID de fleet) sin FK dura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_odometer_readings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_id')->index();
            // Lectura en kilómetros enteros (la unidad humana del contexto).
            $table->integer('km');
            // Origen de la lectura: manual | telemetry | refuel | maintenance | departure.
            $table->string('source');
            $table->timestampTz('read_at')->index();
            $table->string('note')->nullable();
            $table->timestamps();

            // Índice compuesto: última lectura de un vehículo y rangos por fecha.
            $table->index(['vehicle_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_odometer_readings');
    }
};
