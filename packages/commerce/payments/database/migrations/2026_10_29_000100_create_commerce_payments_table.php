<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_payments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // UUID de la orden que se cobra (referencia por id; payments no depende del contexto orders).
            $table->uuid('order_id')->index();
            $table->integer('amount_cents');
            $table->string('currency');
            // qr_bcb | bank_transfer | card | cash
            $table->string('method');
            // pending | authorized | captured | failed | refunded
            $table->string('status')->index();
            // Referencia del cobro del proveedor (PCI: nunca datos de tarjeta). Nula hasta autorizar/capturar.
            $table->string('gateway_ref')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_payments');
    }
};
