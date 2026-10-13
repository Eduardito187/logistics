<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de líneas del envío: un producto con su cantidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('sku');
            $table->string('product_name');
            $table->unsignedInteger('quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');
    }
};
