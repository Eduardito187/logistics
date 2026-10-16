<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cabecera de la liquidación de un seller por los despachos de un periodo (Fase 4, ADR 017).
 *
 * Montos en centavos (entero) + moneda para evitar errores de redondeo. La comisión guarda sus basis
 * points para trazar el cálculo. Los fees diferidos (storage/fulfillment/last_mile) existen desde ya pero
 * HOY valen 0 (hueco de Fases 2–3). PK UUID v7 en texto (la genera el dominio). Idempotente: no recrea.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_settlements')) {
            return;
        }

        Schema::create('seller_settlements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Seller dueño, por id como texto (sin FK dura: contexto desacoplable a microservicio).
            $table->uuid('seller_id')->index();
            // Rango del periodo liquidado (inclusive).
            $table->date('period_from');
            $table->date('period_to');
            // Bruto del periodo (suma de los montos base de los despachos).
            $table->integer('gross_cents');
            $table->string('currency', 3);
            // Comisión del marketplace: tasa en basis points + monto resultante.
            $table->integer('commission_basis_points');
            $table->integer('commission_cents');
            // Fee fijo total (fee por despacho × nº de despachos).
            $table->integer('fixed_fee_cents');
            // Fees diferidos (HOY 0): almacenaje, fulfillment y última milla (ver AppliedFees).
            $table->integer('storage_fee_cents')->default(0);
            $table->integer('fulfillment_fee_cents')->default(0);
            $table->integer('last_mile_fee_cents')->default(0);
            // Neto a pagar al seller (bruto menos todos los fees).
            $table->integer('net_cents');
            // Estado del ciclo de vida: draft, confirmed, paid.
            $table->string('status')->index();
            // Referencia del pago (si fue pagada).
            $table->string('payment_reference')->nullable();
            $table->timestamps();

            // Índice por seller + inicio de periodo: acelera el statement de un seller.
            $table->index(['seller_id', 'period_from'], 'seller_settlements_seller_period_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlements');
    }
};
