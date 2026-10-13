<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de escaneos: cada escaneo en terreno es un hecho inmutable (append-only), con su etapa, bulto,
 * GPS, dispositivo/usuario y hora. El índice único en idempotency_key respalda la idempotencia offline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_scan_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('delivery_id')->index();
            $table->string('type');
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
        Schema::dropIfExists('execution_scan_events');
    }
};
