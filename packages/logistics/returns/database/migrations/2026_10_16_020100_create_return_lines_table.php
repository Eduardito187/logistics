<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('return_id')->index();
            // SKU devuelto (código interno de la unidad etiquetada).
            $table->string('sku')->index();
            $table->integer('quantity');
            // Motivo de la devolución (texto libre del canal/operador).
            $table->string('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_lines');
    }
};
