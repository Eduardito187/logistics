<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_maintenance_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Vehículo de la flota propia por su id como texto (UUID de fleet; sin FK dura: desacoplable).
            $table->string('vehicle_id')->index();
            // Tipo (preventive|corrective|inspection) y estado del ciclo de vida.
            $table->string('type')->index();
            $table->string('status')->index();
            // Odómetro en km al momento de la orden (opcional).
            $table->integer('odometer_km')->nullable();
            $table->string('workshop')->nullable();
            // Ítems de la orden (repuestos + mano de obra) en jsonb; la moneda la da la columna currency.
            $table->jsonb('items');
            // Costo total en centavos (entero) para evitar errores de redondeo.
            $table->integer('total_cost_cents');
            $table->string('currency');
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_maintenance_orders');
    }
};
