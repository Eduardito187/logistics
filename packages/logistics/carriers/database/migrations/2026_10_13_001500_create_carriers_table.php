<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carriers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('tax_id')->unique();
            $table->string('status')->index();
            // Promedio de calificación (1.00–5.00). Nulo mientras no haya ninguna calificación.
            $table->decimal('rating', 3, 2)->nullable();
            // Cantidad de calificaciones acumuladas: permite recalcular el promedio tras recargar.
            $table->integer('rating_count')->default(0);
            $table->jsonb('documents');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('carriers');
    }
};
