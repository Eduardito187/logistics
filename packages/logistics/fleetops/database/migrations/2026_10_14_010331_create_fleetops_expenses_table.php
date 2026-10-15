<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_expenses', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Referencia al vehículo por su id de fleet (texto, sin FK dura: contexto desacoplable).
            $table->string('vehicle_id')->index();
            $table->string('category')->index();
            // Importe guardado en centavos (entero) para evitar errores de redondeo.
            $table->integer('amount_cents');
            $table->string('currency');
            $table->string('description')->nullable();
            // Fecha/hora en que se incurrió el gasto (con zona horaria).
            $table->timestampTz('incurred_at')->index();
            $table->string('note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_expenses');
    }
};
