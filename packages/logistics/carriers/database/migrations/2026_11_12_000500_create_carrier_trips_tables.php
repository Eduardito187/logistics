<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Viajes de transportista (carga asignada) y su carga (envíos), auditoría app pedido #4.
 *
 * ADITIVA e IDEMPOTENTE (regla del proyecto: nunca destructivo): cada tabla se crea solo si no existe,
 * así re-correr la migración en un entorno donde ya está no falla ni pisa datos.
 *
 * - `carrier_trips`: cabecera del viaje. Nodos y transportista como UUID en texto SIN FK dura (contexto
 *   desacoplable/extraíble). Actores (`started_by`/`closed_by`) como varchar: el id de usuario del admin
 *   es bigint, no uuid (gotcha documentado).
 * - `carrier_trip_shipments`: envíos de la carga, PK compuesta (viaje, envío) → no se duplica un envío
 *   en el mismo viaje; índice por envío para la regla "un envío en un solo viaje vigente".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('carrier_trips')) {
            Schema::create('carrier_trips', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('carrier_id');
                $table->string('corridor_from');
                $table->string('corridor_to');
                $table->string('reference')->nullable();
                $table->string('status', 32);
                $table->timestampTz('scheduled_at');
                $table->timestampTz('started_at')->nullable();
                $table->timestampTz('closed_at')->nullable();
                $table->timestampTz('cancelled_at')->nullable();
                $table->string('started_by', 64)->nullable();
                $table->string('closed_by', 64)->nullable();
                $table->timestamps();

                // Listado del transportista: filtra por transportista + estado y ordena por fecha programada.
                $table->index(['carrier_id', 'status', 'scheduled_at'], 'carrier_trips_carrier_status_scheduled_index');
                // Listado de oficina (todos los transportistas) por estado + fecha.
                $table->index(['status', 'scheduled_at'], 'carrier_trips_status_scheduled_index');
            });
        }

        if (!Schema::hasTable('carrier_trip_shipments')) {
            Schema::create('carrier_trip_shipments', function (Blueprint $table): void {
                $table->uuid('trip_id');
                $table->uuid('shipment_id');
                // Orden de carga dentro del viaje (orden en que se asignaron).
                $table->unsignedInteger('position')->default(0);
                $table->timestampTz('created_at')->nullable();

                $table->primary(['trip_id', 'shipment_id']);
                $table->index('shipment_id', 'carrier_trip_shipments_shipment_id_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_trip_shipments');
        Schema::dropIfExists('carrier_trips');
    }
};
