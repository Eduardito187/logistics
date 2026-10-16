<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('lot_id');
            $table->uuid('warehouse_id');
            $table->string('sku');
            // Dimensión de propiedad replicada en el ledger para reportería por dueño sin join.
            $table->string('fulfillment_mode')->default('owned');
            $table->uuid('owner_party_id')->nullable();
            // received | reserved | released | picked | transferred | adjusted
            $table->string('movement_type');
            // Cantidad de la operación (en adjust puede ser negativa).
            $table->bigInteger('quantity');
            $table->string('reference')->nullable();
            // Almacén relacionado (destino/origen) en una transferencia.
            $table->uuid('related_warehouse_id')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index('lot_id');
            $table->index(['warehouse_id', 'sku']);
            $table->index(['owner_party_id', 'occurred_at'], 'inventory_movements_owner_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
    }
};
