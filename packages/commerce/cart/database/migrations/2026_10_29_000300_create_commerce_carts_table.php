<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_carts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Dueño del carrito; null = invitado (se identifica por session_id).
            $table->uuid('customer_id')->nullable()->index();
            // Sesión del storefront que posee el carrito (clave para carritos de invitado).
            $table->string('session_id')->index();
            // open | converted | abandoned
            $table->string('status')->index();
            // Moneda ISO-4217 (Dismac: BOB por defecto).
            $table->string('currency', 3);
            // Líneas como JSONB (value objects del agregado, no entidades consultables aparte).
            $table->jsonb('lines')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_carts');
    }
};
