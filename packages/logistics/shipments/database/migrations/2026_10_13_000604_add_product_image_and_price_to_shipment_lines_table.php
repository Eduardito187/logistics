<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a la línea del envío los datos OPCIONALES de presentación que consume el notificador:
 * la URL de la imagen del producto (para miniatura en mail/push) y el precio unitario (para el
 * detalle de productos en el mail). Ambas columnas son nullable: la ingesta existente no las manda.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipment_lines', function (Blueprint $table): void {
            $table->string('product_image_url', 2048)->nullable()->after('product_name');
            $table->decimal('unit_price', 12, 2)->nullable()->after('product_image_url');
            $table->string('unit_price_currency', 3)->nullable()->after('unit_price');
        });
    }

    public function down(): void
    {
        Schema::table('shipment_lines', function (Blueprint $table): void {
            $table->dropColumn(['product_image_url', 'unit_price', 'unit_price_currency']);
        });
    }
};
