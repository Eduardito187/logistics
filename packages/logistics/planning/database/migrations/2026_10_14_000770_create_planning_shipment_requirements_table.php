<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requisitos de RUTEO por envío (R-04), read-side propio de Planificación: el tipo de carga del envío, su
 * zona de destino y las dimensiones físicas (altura y peso del bulto) que el camión debe poder manejar. El
 * adaptador de pendientes los lee en lote y arma el ShipmentRouteRequirement de cada candidato.
 *
 * Todas las columnas son opcionales: una fila ausente o con todo en null es un envío SIN requisitos
 * (entra en cualquier camión; comportamiento previo). Es aditiva: no toca el contexto de Envíos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_shipment_requirements', function (Blueprint $table): void {
            // PK = id del envío (UUID de Shipments): una fila de requisitos por envío.
            $table->uuid('shipment_id')->primary();

            $table->string('load_type')->nullable();
            $table->string('zone')->nullable();
            $table->decimal('height_m', 6, 2)->nullable();
            $table->decimal('weight_kg', 10, 2)->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_shipment_requirements');
    }
};
