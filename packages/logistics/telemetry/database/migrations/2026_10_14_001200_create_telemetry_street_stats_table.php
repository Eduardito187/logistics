<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_street_stats', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Clave estable del tramo (hash de los nodos OSM que lo delimitan): acumula muestras en el tiempo.
            $table->string('segment_key')->unique();
            $table->string('name')->nullable();
            // Polilínea del tramo como lista JSON de puntos {lat,lng} (sin PostGIS).
            $table->jsonb('geometry');
            $table->integer('sample_count')->default(0);
            $table->decimal('avg_speed_kph', 6, 2)->default(0);
            $table->decimal('min_speed_kph', 6, 2)->default(0);
            $table->integer('total_seconds')->default(0);
            $table->timestampTz('last_sampled_at')->nullable();
            // 24 enteros: cantidad de muestras por hora del día (0–23), para el perfil horario de la calle.
            $table->jsonb('hour_histogram');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_street_stats');
    }
};
