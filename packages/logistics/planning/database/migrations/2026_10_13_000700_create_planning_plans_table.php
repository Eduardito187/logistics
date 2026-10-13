<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de planes de reparto: un plan por camión y día, con su estado (Draft/Proposed/Published).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_plans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('plan_date')->index();
            $table->uuid('vehicle_id')->index();
            $table->string('status')->index();
            $table->timestamps();

            // Un plan por (día, vehículo): el camión tiene una sola hoja de ruta por jornada.
            $table->unique(['plan_date', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_plans');
    }
};
