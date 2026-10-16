<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_receipt_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('goods_receipt_id')->index();
            $table->string('sku')->index();
            // Desglose por resultado de QC: aceptado (entra a stock), rechazado y dañado.
            $table->integer('accepted_quantity');
            $table->integer('rejected_quantity');
            $table->integer('damaged_quantity');
            // accepted | rejected | partial_damage
            $table->string('qc_outcome');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_receipt_lines');
    }
};
