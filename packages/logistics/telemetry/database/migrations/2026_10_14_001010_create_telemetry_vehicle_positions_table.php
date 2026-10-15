<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Posición EN VIVO: una sola fila por vehículo, pisada con cada ping nuevo. Es la proyección que pinta la
 * flota en el mapa sin escanear la tabla de pings. La PK es el vehicle_id (string, del contexto fleet).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_vehicle_positions', function (Blueprint $table): void {
            $table->string('vehicle_id')->primary();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_kph', 6, 2)->nullable();
            $table->smallInteger('heading')->nullable();
            $table->boolean('ignition')->nullable();
            $table->boolean('moving');
            $table->string('route_id')->nullable();
            $table->timestampTz('recorded_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_vehicle_positions');
    }
};
