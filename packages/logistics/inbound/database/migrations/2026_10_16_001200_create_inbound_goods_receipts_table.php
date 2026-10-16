<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_goods_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('asn_id')->unique();
            $table->uuid('warehouse_id')->index();
            // Dimensión de propiedad (shared Ownership): dueño seller + modo FBD/dropship.
            $table->uuid('owner_party_id')->index();
            $table->string('fulfillment_mode');
            $table->string('seller_sku_ref')->nullable();
            // Punto de convergencia: true cuando se etiquetaron las unidades aceptadas.
            $table->boolean('labeled')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_goods_receipts');
    }
};
