<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_skus', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('seller_id')->index();
            // SKU propio del seller.
            $table->string('seller_sku');
            // Material SAP equivalente (opcional; se completa cuando el sap-connector lo necesite).
            $table->string('sap_material')->nullable();
            $table->timestamps();

            // Clave natural del catálogo: un SKU único por seller.
            $table->unique(['seller_id', 'seller_sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_skus');
    }
};
