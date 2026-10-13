<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trips: tramos continuos de conducción, con sus métricas ya agregadas (distancia, duración,
 * movimiento/inactividad, velocidad máxima, cantidad de puntos). Evita recorrer los pings para resumir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_trips', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_id')->index();
            $table->string('driver_id')->nullable();
            $table->string('route_id')->nullable();
            $table->string('status')->index();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->decimal('start_lat', 10, 7);
            $table->decimal('start_lng', 10, 7);
            $table->decimal('end_lat', 10, 7)->nullable();
            $table->decimal('end_lng', 10, 7)->nullable();
            $table->decimal('distance_m', 12, 2);
            $table->integer('duration_s');
            $table->integer('moving_s');
            $table->integer('idle_s');
            $table->decimal('max_speed_kph', 6, 2);
            $table->integer('ping_count');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_trips');
    }
};
