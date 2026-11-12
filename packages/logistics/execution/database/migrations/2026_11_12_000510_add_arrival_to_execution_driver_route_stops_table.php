<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Llegada del camión a cada parada (POST /app/deliveries/{shipmentId}/arrived): hora de la PRIMERA llegada
 * y su origen (geofence|manual). Prerrequisito de los KPIs de tiempo en parada. ADITIVA, nullable e
 * idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_driver_route_stops', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_driver_route_stops', 'arrived_at')) {
                $table->timestampTz('arrived_at')->nullable();
            }
            if (!Schema::hasColumn('execution_driver_route_stops', 'arrival_source')) {
                $table->string('arrival_source', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_driver_route_stops', function (Blueprint $table): void {
            foreach (['arrived_at', 'arrival_source'] as $column) {
                if (Schema::hasColumn('execution_driver_route_stops', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
