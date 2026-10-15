<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de bultos recibidos en un nodo: cada bulto escaneado al recibir es un hecho inmutable
 * (append-only), con su GPS, dispositivo/usuario, hora y clave de idempotencia. El índice único en
 * idempotency_key respalda la idempotencia offline a nivel de base, además de la verificación en el
 * agregado.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_node_reception_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_reception_id')->index();
            $table->string('unit_id')->index();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            $table->string('device_id');
            $table->string('user_id');
            $table->timestampTz('occurred_at');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_node_reception_units');
    }
};
