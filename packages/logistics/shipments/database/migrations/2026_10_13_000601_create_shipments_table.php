<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de envíos: la cabecera del pedido normalizado al modelo propio (fuera de SAP).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source')->index();
            $table->string('external_id');
            $table->string('customer_name');
            $table->string('address_text');
            $table->string('address_reference')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->unsignedTinyInteger('address_confidence');
            $table->string('address_confidence_level');
            $table->string('status')->index();
            $table->timestamps();

            // Idempotencia de ingreso: un pedido por (fuente, id externo).
            $table->unique(['source', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
