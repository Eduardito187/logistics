<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_promotions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            // null = promoción automática; no-null = cupón (código normalizado en mayúsculas, único).
            $table->string('code')->nullable()->unique();
            // percentage_off | fixed_amount_off | free_shipping
            $table->string('type')->index();
            // Según el tipo: basis points (0..10000) para porcentaje, centavos para monto fijo, 0 para envío.
            $table->bigInteger('value')->default(0);
            // Moneda del monto fijo (BOB por defecto en Dismac).
            $table->string('currency', 3)->default('BOB');
            // Condiciones de aplicación (subtotal mínimo, canal, ventana de fechas) como JSONB.
            $table->jsonb('conditions')->default('{}');
            // draft | active | expired
            $table->string('status')->index();
            // Versión de las reglas: arranca en 1, se incrementa en cada changeRules (versionado).
            $table->integer('version')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_promotions');
    }
};
