<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carrier_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Referencia al transportista por su id como texto (sin FK dura: contexto desacoplable).
            $table->uuid('carrier_id')->index();
            // Corredor origen→destino + tipo, en el mismo vocabulario que network pero como texto.
            $table->string('corridor_from');
            $table->string('corridor_to');
            $table->string('corridor_type');
            // Período de facturación mensual en formato YYYY-MM.
            $table->string('period', 7);
            // Monto adeudado en centavos (entero) para evitar errores de redondeo.
            $table->integer('amount_cents');
            $table->string('currency');
            // Referencia externa del transportista (p. ej. número de factura).
            $table->string('reference');
            // Estado del ciclo de vida: submitted, approved, paid, disputed.
            $table->string('status')->index();
            // Motivo de objeción (si fue objetada) y referencia del pago (si fue pagada).
            $table->string('dispute_reason')->nullable();
            $table->string('payment_reference')->nullable();
            $table->jsonb('documents');
            $table->timestamps();

            // Índice por transportista + período: acelera el listado de liquidaciones de un transportista.
            $table->index(['carrier_id', 'period'], 'carrier_settlements_carrier_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carrier_settlements');
    }
};
