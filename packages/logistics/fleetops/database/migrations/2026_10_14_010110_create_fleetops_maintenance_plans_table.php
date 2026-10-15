<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_maintenance_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Vehículo de la flota propia por su id como texto (UUID de fleet; sin FK dura: desacoplable).
            $table->string('vehicle_id')->index();
            // Clase de servicio preventivo vigilado por el plan (oil_change, tires, brakes, ...).
            $table->string('service_kind');
            // Intervalos del plan: por km y/o por días (al menos uno, lo garantiza el dominio).
            $table->integer('interval_km')->nullable();
            $table->integer('interval_days')->nullable();
            // Último servicio ejecutado (km y fecha) y próximo vencimiento calculado.
            $table->integer('last_done_km')->nullable();
            $table->timestampTz('last_done_at')->nullable();
            $table->integer('next_due_km')->nullable();
            $table->timestampTz('next_due_at')->nullable();
            // Plan activo: si está inactivo, no vigila vencimientos.
            $table->boolean('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_maintenance_plans');
    }
};
