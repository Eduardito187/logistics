<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de paradas del plan: la entrega de un envío en una posición de la secuencia de reparto, con su
 * ETA (instante + minutos desde la salida) y su carril de carga. ETA y carril pueden ser nulos hasta
 * que el plan se propone y se recalcula el plan de carga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_plan_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('plan_id')->index();
            $table->uuid('shipment_id')->index();
            $table->unsignedInteger('sequence');
            $table->timestampTz('eta')->nullable();
            $table->unsignedInteger('eta_minutes')->nullable();
            $table->string('lane')->nullable();
            $table->timestamps();

            // Un envío no puede aparecer dos veces en el mismo plan, ni dos paradas compartir posición.
            $table->unique(['plan_id', 'shipment_id']);
            $table->unique(['plan_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_plan_stops');
    }
};
