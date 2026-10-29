<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_orders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('customer_id')->nullable()->index();
            // pending | paid | fulfilling | cancelled
            $table->string('status')->index();
            $table->string('currency', 3);
            // true cuando ya se solicitó el envío (idempotencia del puente a fulfillment).
            $table->boolean('shipment_requested')->default(false);
            // Dirección de entrega (JSON) y líneas con precio congelado (JSONB).
            $table->jsonb('ship_to');
            $table->jsonb('lines')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_orders');
    }
};
