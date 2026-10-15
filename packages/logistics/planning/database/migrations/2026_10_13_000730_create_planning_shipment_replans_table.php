<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog de replanificación: envíos que quedaron sin entregar (p. ej. por un incidente MAYOR del camión)
 * y deben volver a planificarse para una fecha. La planificación de ese día consume esta cola.
 *
 * Único por (shipment_id, plan_date): un envío no se encola dos veces para el mismo día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('planning_shipment_replans', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->date('plan_date')->index();
            $table->string('reason');
            $table->uuid('source_route_id')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'plan_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('planning_shipment_replans');
    }
};
