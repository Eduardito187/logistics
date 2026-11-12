<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resultado del checklist contra la plantilla del servidor (auditoría #27): si quedó apto para despachar,
 * qué plantilla se aplicó y la evaluación (ítems críticos/mayores/menores fallidos, críticos faltantes).
 *
 * ADITIVO e idempotente. Las inspecciones históricas quedan `fit_to_dispatch = true` (no se evaluaban).
 * Índice compuesto (vehicle_id, performed_on) para el listado paginado y el «último checklist» del vehículo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('fleet_pre_trip_checklists', 'fit_to_dispatch')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->boolean('fit_to_dispatch')->default(true)->index();
            });
        }
        if (!Schema::hasColumn('fleet_pre_trip_checklists', 'checklist_template_id')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->uuid('checklist_template_id')->nullable();
            });
        }
        if (!Schema::hasColumn('fleet_pre_trip_checklists', 'evaluation')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->jsonb('evaluation')->nullable();
            });
        }
        if (!Schema::hasIndex('fleet_pre_trip_checklists', 'fleet_pre_trip_checklists_vehicle_performed_idx')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->index(['vehicle_id', 'performed_on'], 'fleet_pre_trip_checklists_vehicle_performed_idx');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('fleet_pre_trip_checklists', 'fleet_pre_trip_checklists_vehicle_performed_idx')) {
            Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table): void {
                $table->dropIndex('fleet_pre_trip_checklists_vehicle_performed_idx');
            });
        }
        foreach (['evaluation', 'checklist_template_id', 'fit_to_dispatch'] as $name) {
            if (Schema::hasColumn('fleet_pre_trip_checklists', $name)) {
                Schema::table('fleet_pre_trip_checklists', static function (Blueprint $table) use ($name): void {
                    $table->dropColumn($name);
                });
            }
        }
    }
};
