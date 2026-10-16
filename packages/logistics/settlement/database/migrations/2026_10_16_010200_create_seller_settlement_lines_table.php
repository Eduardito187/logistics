<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Líneas de la liquidación de un seller: un despacho del periodo = una línea con su monto base.
 *
 * El despacho se referencia por el UUID del envío (`shipment_id`, contexto `shipments`, sin FK dura) y por
 * su referencia legible (`dispatch_reference`, el id externo del pedido). PK UUID en texto. Idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('seller_settlement_lines')) {
            return;
        }

        Schema::create('seller_settlement_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('settlement_id')->index();
            // Envío liquidado (contexto shipments) y su referencia legible.
            $table->uuid('shipment_id')->index();
            $table->string('dispatch_reference');
            // Monto base del despacho sobre el que se calcula la comisión.
            $table->integer('base_amount_cents');
            $table->string('currency', 3);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlement_lines');
    }
};
