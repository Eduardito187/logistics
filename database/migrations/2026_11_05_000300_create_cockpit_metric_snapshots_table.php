<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto histórica diaria de los KPIs de la Torre de Control, para calcular la VARIACIÓN (delta ▲/▼ vs el
 * periodo anterior) en los mosaicos `.ck-stat`. Hasta ahora el delta iba null por falta de línea base;
 * un comando agendado (`cockpit:metrics:snapshot`) escribe una fila por métrica y día, y el pulso compara
 * el valor de hoy con el último snapshot anterior. Aditiva e idempotente (una fila por métrica+fecha).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cockpit_metric_snapshots')) {
            return;
        }

        Schema::create('cockpit_metric_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->string('metric_key');
            $table->date('captured_on');
            $table->bigInteger('value');
            $table->timestamps();

            $table->unique(['metric_key', 'captured_on']);
            $table->index('captured_on');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpit_metric_snapshots');
    }
};
