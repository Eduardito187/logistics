<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_products', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Dueño del producto (seller de marketplace); null = producto propio OWNED de Dismac (ADR 017).
            $table->uuid('seller_id')->nullable()->index();
            $table->uuid('category_id')->nullable()->index();
            $table->string('name')->index();
            $table->text('description')->nullable();
            // SKU canónico: puente 1:1 con inventory (ATP). No es UUID.
            $table->string('canonical_sku')->unique();
            // draft | published | archived
            $table->string('status')->index();
            // Variantes y medios como JSONB (value objects del agregado, no entidades consultables aparte).
            $table->jsonb('variants')->default('[]');
            $table->jsonb('media')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_products');
    }
};
