<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de incidentes de camión reportados por el chofer (avería o accidente). PK uuid string no
 * incremental (UUID v7 del dominio). Es un hecho inmutable: solo se inserta.
 *
 * Guarda la gravedad (MINOR/MAJOR), el tipo (BREAKDOWN/ACCIDENT), la nota y el GPS del chofer, los
 * envíos afectados (las paradas que seguían pendientes), y el desenlace automático: fecha de
 * reprogramación (solo MAJOR) o minutos de retraso aplicados (solo MINOR).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_vehicle_incidents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('route_id')->index();
            $table->uuid('vehicle_id')->index();
            $table->string('severity')->index();
            $table->string('type');
            $table->text('note')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('reported_by_user')->nullable();
            $table->string('reported_by_device')->nullable();
            $table->jsonb('affected_shipment_ids');
            $table->date('reschedule_date')->nullable();
            $table->unsignedInteger('delay_minutes')->nullable();
            $table->timestampTz('occurred_at');
            // Clave de idempotencia de la app offline del chofer: el reenvío del mismo reporte no duplica.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_vehicle_incidents');
    }
};
