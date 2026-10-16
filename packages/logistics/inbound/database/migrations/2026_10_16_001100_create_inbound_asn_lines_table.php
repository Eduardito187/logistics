<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_asn_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('asn_id')->index();
            // SKU del seller (código propio); su mapeo a material SAP lo resuelve el sap-connector.
            $table->string('sku')->index();
            $table->integer('expected_quantity');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_asn_lines');
    }
};
