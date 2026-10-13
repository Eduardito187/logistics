<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de entregas: una por envío. Guarda el estado de ejecución y el desenlace (prueba de entrega
 * o motivo de fallo). Los escaneos viven en execution_scan_events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_deliveries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->unique();
            $table->uuid('vehicle_id')->index();
            $table->string('status')->index();
            $table->string('pod_confirmation')->nullable();
            $table->string('pod_photo_url')->nullable();
            $table->timestampTz('pod_captured_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_deliveries');
    }
};
