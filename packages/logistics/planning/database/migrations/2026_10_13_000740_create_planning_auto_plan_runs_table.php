<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Corridas del auto-planificador diario: el ciclo asíncrono (queued/processing/completed/failed) y el
 * resumen del resultado. Da la idempotencia por (día, modo) y la traza del disparo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_auto_plan_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->date('plan_date');
            $table->string('strategy');
            $table->string('status');
            $table->timestampTz('requested_at');
            $table->timestampTz('completed_at')->nullable();
            $table->unsignedInteger('trucks_planned')->default(0);
            $table->unsignedInteger('stops_planned')->default(0);
            $table->unsignedInteger('unassigned_count')->default(0);
            $table->text('failure_reason')->nullable();
            $table->timestamps();

            // Consultas por día+modo (idempotencia: buscar la corrida activa/última) y por estado.
            $table->index(['plan_date', 'strategy', 'status']);
            $table->index(['plan_date', 'strategy', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_auto_plan_runs');
    }
};
