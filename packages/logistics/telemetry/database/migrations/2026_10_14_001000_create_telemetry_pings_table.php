<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla append-only de pings (lecturas GPS crudas). Alto volumen: millones de filas. El índice compuesto
 * (vehicle_id, recorded_at) sirve tanto la traza de un vehículo como la analítica por rango de tiempo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_pings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('vehicle_id')->index();
            $table->string('driver_id')->nullable();
            $table->string('device_id')->nullable();
            $table->string('route_id')->nullable()->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->decimal('speed_kph', 6, 2)->nullable();
            $table->smallInteger('heading')->nullable();
            $table->decimal('accuracy_m', 7, 2)->nullable();
            $table->boolean('ignition')->nullable();
            $table->bigInteger('odometer_m')->nullable();
            $table->timestampTz('recorded_at')->index();
            $table->timestampTz('received_at');
            $table->string('source');
            $table->uuid('trip_id')->nullable()->index();
            $table->timestamps();

            $table->index(['vehicle_id', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_pings');
    }
};
