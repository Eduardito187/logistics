<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_invoices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // UUID de la orden facturada. Único: una orden tiene a lo sumo una factura (idempotencia del
            // puente orders.order_paid → invoicing). Referencia por id; invoicing no depende de orders.
            $table->uuid('order_id')->unique();
            // Número fiscal asignado por el sistema tributario al emitir. Nulo mientras está en Draft.
            $table->string('number')->nullable();
            $table->string('customer_name');
            // NIT/CI del cliente (dato fiscal, no es un UUID). Nulo si la venta es sin nombre ("S/N").
            $table->string('customer_tax_id')->nullable();
            $table->string('currency');
            // Líneas de la factura (snapshot inmutable): description, quantity, unit_price_cents, line_total_cents.
            $table->jsonb('lines')->default('[]');
            // Neto (base imponible, sin IVA).
            $table->integer('subtotal_cents');
            // IVA (13 % por dentro, incluido en el total).
            $table->integer('tax_cents');
            // Bruto (IVA incluido): suma de los line_total_cents.
            $table->integer('total_cents');
            // draft | issued | cancelled
            $table->string('status')->index();
            // Momento de emisión del documento fiscal. Nulo mientras está en Draft.
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_invoices');
    }
};
