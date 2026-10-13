<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de unidades físicas (bultos): un bulto por unidad de producto, con su "N de M" de unidad y
 * de pieza.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipment_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->uuid('line_id')->index();
            $table->unsignedInteger('unit_index');
            $table->unsignedInteger('unit_total');
            $table->unsignedInteger('piece_index');
            $table->unsignedInteger('piece_total');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_units');
    }
};
