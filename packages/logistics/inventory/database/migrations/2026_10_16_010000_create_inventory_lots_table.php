<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_lots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Almacén (node id de network) donde está físicamente el lote.
            $table->uuid('warehouse_id');
            $table->string('sku');
            // Dimensión de propiedad (ADR 017): owned | fbd | dropship. Default owned (retail intacto).
            $table->string('fulfillment_mode')->default('owned');
            // Null = propio de Dismac; no-null = seller dueño (consignación). Segregación lógica.
            $table->uuid('owner_party_id')->nullable();
            $table->string('seller_sku_ref')->nullable();
            $table->unsignedBigInteger('available_quantity')->default(0);
            $table->unsignedBigInteger('reserved_quantity')->default(0);
            $table->bigInteger('unit_cost_cents')->default(0);
            $table->string('currency', 3)->default('BOB');
            $table->timestamp('received_at');
            // Trazabilidad al goods receipt de inbound que originó el lote.
            $table->string('goods_receipt_ref')->nullable();
            $table->timestamps();

            // Índices pensados para millones de lotes: consulta on-hand / ATP por almacén+sku+dueño.
            $table->index(['warehouse_id', 'sku', 'owner_party_id'], 'inventory_lots_wh_sku_owner_idx');
            $table->index(['warehouse_id', 'sku', 'fulfillment_mode'], 'inventory_lots_wh_sku_mode_idx');
            $table->index('owner_party_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_lots');
    }
};
