<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seller_settlement_terms', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Un seller tiene a lo sumo un juego de términos vigentes.
            $table->uuid('seller_id')->unique();
            // Comisión en basis points (1 bp = 0,01 %): 1500 = 15 %.
            $table->integer('commission_basis_points');
            // Fee fijo por operación en centavos + moneda (default BOB).
            $table->integer('fixed_fee_cents');
            $table->string('currency', 3);
            // weekly | biweekly | monthly
            $table->string('payment_cycle');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seller_settlement_terms');
    }
};
