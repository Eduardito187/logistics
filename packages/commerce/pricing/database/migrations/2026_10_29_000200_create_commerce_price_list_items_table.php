<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_price_list_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('price_list_id')->index();
            // SKU canónico del producto (ver commerce/catalog / inventory).
            $table->string('sku');
            // Precio al público (IVA incluido) en centavos (entero), nunca float.
            $table->integer('gross_amount_cents');
            // Moneda del precio (ISO 4217), p. ej. BOB.
            $table->string('currency');
            $table->timestamps();

            // Clave natural del catálogo de precios: un precio único por (lista, SKU).
            $table->unique(['price_list_id', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_price_list_items');
    }
};
