<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_geofences', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            // Tipo de geocerca (ver GeofenceType): decide la semántica de una incursión.
            $table->string('type')->index();
            // Polígono como lista JSON de pares [lat,lng]. El repo NO usa PostGIS; la pertenencia
            // (point-in-polygon) se resuelve en PHP con ray casting.
            $table->jsonb('polygon');
            $table->boolean('active')->default(true);
            // Referencia opcional a un nodo de la red logística (centro, tienda), como texto opaco.
            $table->string('node_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_geofences');
    }
};
