<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restricciones de RUTEO por camión (R-04), read-side propio de Planificación: qué tipos de carga puede
 * llevar, a qué zonas puede/no puede entrar y sus límites físicos de altura y peso por bulto. Planificación
 * las posee (no vive en Fleet) para no acoplar su esquema; el adaptador de disponibilidad las lee en lote y
 * arma el VehicleRouteProfile de cada camión.
 *
 * Todas las columnas son opcionales: una fila ausente o con todo en null es un camión SIN restricciones
 * (comportamiento previo del solver). Es aditiva: no toca fleet_vehicles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_vehicle_restrictions', function (Blueprint $table): void {
            // PK = id del vehículo (UUID de Fleet): una fila de restricciones por camión.
            $table->uuid('vehicle_id')->primary();

            // Listas blancas/negra de tipos de carga y zonas (jsonb). null = sin restricción de ese tipo.
            $table->jsonb('allowed_load_types')->nullable();
            $table->jsonb('allowed_zones')->nullable();
            $table->jsonb('forbidden_zones')->nullable();

            // Límites físicos (null = sin límite).
            $table->decimal('max_height_m', 6, 2)->nullable();
            $table->decimal('max_weight_kg', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_vehicle_restrictions');
    }
};
