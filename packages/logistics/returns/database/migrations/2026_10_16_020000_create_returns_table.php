<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('returns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Referencia al envío/pedido original que se devuelve.
            $table->string('original_reference')->index();
            // Dimensión de propiedad (shared Ownership): dueño seller (null = retail) + modo owned/fbd.
            $table->uuid('owner_party_id')->nullable()->index();
            $table->string('fulfillment_mode')->default('owned');
            // Almacén central de retorno (contexto network).
            $table->uuid('warehouse_id')->index();
            // customer_return | return_to_vendor
            $table->string('kind')->index();
            // restock | return_to_seller | scrap (nullable hasta disponer)
            $table->string('disposition')->nullable();
            // initiated | received | dispositioned | closed
            $table->string('status')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('returns');
    }
};
