<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ETA dinámica por parada restante (una fila por envío pendiente de entregar), recalculada desde la última
 * posición del vehículo por el comando programado `telemetry:refresh-etas`. La lectura de la API usa esta
 * proyección; si no hay fila (scheduler apagado), calcula en línea con el respaldo en línea recta.
 *
 * `method` = 'osrm' (duraciones viales OSRM, sin tráfico) | 'straight_line' (distancia / velocidad media).
 * Aditiva e idempotente (hasTable). Nunca destructiva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('telemetry_stop_etas')) {
            return;
        }

        Schema::create('telemetry_stop_etas', function (Blueprint $table): void {
            $table->string('shipment_id')->primary();
            $table->string('vehicle_id')->index();
            $table->unsignedInteger('sequence');
            $table->decimal('stop_lat', 10, 7)->nullable();
            $table->decimal('stop_lng', 10, 7)->nullable();
            $table->timestampTz('eta_at')->nullable();
            $table->unsignedInteger('remaining_distance_m')->nullable();
            $table->unsignedInteger('remaining_duration_s')->nullable();
            $table->string('method', 32);
            $table->timestampTz('position_recorded_at');
            $table->timestampTz('computed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_stop_etas');
    }
};
