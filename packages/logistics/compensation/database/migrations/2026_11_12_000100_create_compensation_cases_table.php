<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de CASOS de compensación: el problema de entrega y la decisión (propuesta, aprobación, cálculo).
 * Aditiva e idempotente (guard por hasTable): no destruye nada si ya existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compensation_cases')) {
            return;
        }

        Schema::create('compensation_cases', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // DELIVERY_FAILED | LATE_DELIVERY | DAMAGED_PRODUCT | INCOMPLETE | LOW_RATING | MANUAL
            $table->string('trigger')->index();
            // LINE | SHIPMENT | ORDER
            $table->string('scope');
            $table->uuid('shipment_id')->nullable()->index();
            $table->string('order_reference')->nullable()->index();

            // Idempotencia: el event_id de origen (único por reactor) y la clave trigger:shipment.
            $table->uuid('source_event_id')->nullable()->unique();
            $table->string('idempotency_key')->unique();

            // Cálculo (centavos). currency solo BOB por ahora.
            $table->bigInteger('base_cents');
            $table->bigInteger('order_base_cents');
            $table->bigInteger('nominal_cents');
            $table->bigInteger('effective_cents');
            $table->string('currency', 3)->default('BOB');
            $table->jsonb('calculation_trace')->nullable();
            $table->integer('policy_version')->default(1);
            // AUTO | LEVEL1 | LEVEL2
            $table->string('approval_level');

            // proposed | approved | rejected | not_compensable | issued
            $table->string('status')->index();
            // Actores como varchar (gotcha: admin.id es bigint; se guarda como texto).
            $table->string('proposed_by');
            $table->string('approved_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('reason')->nullable();

            // Snapshot de líneas afectadas: nombre, sku, qty, unit_price (nunca se muestra "bulto").
            $table->jsonb('affected_lines')->nullable();

            // Dimensiones para reportes de costo (D2-4: se guarda ownership para poder imputar luego).
            $table->string('ownership')->nullable();
            $table->uuid('driver_id')->nullable();
            $table->uuid('vehicle_id')->nullable();
            $table->uuid('origin_node_id')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_cases');
    }
};
