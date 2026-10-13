<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contador mensual de SMS enviados para el tope configurable (P1-NOT-05). Una fila por período
 * `YYYY-MM` (PK period). El tope vive en la configuración del package; esta tabla solo cuenta el
 * consumo real, que el repositorio incrementa de forma atómica antes de cada envío de SMS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_sms_budgets', function (Blueprint $table): void {
            $table->string('period', 7)->primary();
            $table->unsignedInteger('sent')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_sms_budgets');
    }
};
