<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de escala para los planes de reparto (frente E — escala). Aditivos: solo aceleran lectura.
 *
 *  - (plan_date, status): el panel y el auto-planificador listan los planes de un día acotando por
 *    estado (p. ej. solo los Publicados). El único (plan_date, vehicle_id) ya resuelve el barrido por
 *    día ordenado por vehículo, pero no el filtro por estado; este compuesto sí.
 *
 * `plan_date`, `vehicle_id`, `status` y el único (plan_date, vehicle_id) ya existen desde la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_plans', function (Blueprint $table): void {
            $table->index(['plan_date', 'status'], 'planning_plans_plan_date_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('planning_plans', function (Blueprint $table): void {
            $table->dropIndex('planning_plans_plan_date_status_index');
        });
    }
};
