<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de CONSUMO del backlog de replanificación: `processed_at` queda en null mientras el envío espera
 * ser replanificado para su fecha, y se fija cuando un plan generado para esa fecha lo incluye. El
 * auto-planificador solo lee las filas pendientes (processed_at null) del día que planifica.
 *
 * Aditiva e idempotente: no borra ni reescribe filas existentes (las previas quedan pendientes, que es su
 * estado real: nunca se consumieron). El índice (plan_date, processed_at) cubre la lectura "pendientes del
 * día X" del planificador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('planning_shipment_replans') || Schema::hasColumn('planning_shipment_replans', 'processed_at')) {
            return;
        }

        Schema::table('planning_shipment_replans', function (Blueprint $table): void {
            $table->timestampTz('processed_at')->nullable()->after('source_route_id');
            $table->index(['plan_date', 'processed_at'], 'planning_shipment_replans_date_processed_index');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('planning_shipment_replans') || !Schema::hasColumn('planning_shipment_replans', 'processed_at')) {
            return;
        }

        Schema::table('planning_shipment_replans', function (Blueprint $table): void {
            $table->dropIndex('planning_shipment_replans_date_processed_index');
            $table->dropColumn('processed_at');
        });
    }
};
