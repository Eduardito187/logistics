<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Libro de ítems de los lotes de escaneos (POST /app/deliveries/scans/batch): el resultado por clave de
 * idempotencia de cada ítem, para que un reenvío del lote responda `duplicate` sin volver a ejecutarlo.
 * PK = idempotency_key (la clave del dispositivo). Idempotente: solo se crea si falta.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_scan_batch_items')) {
            return;
        }

        Schema::create('execution_scan_batch_items', function (Blueprint $table): void {
            $table->string('idempotency_key', 191)->primary();
            $table->string('kind', 32);
            $table->uuid('shipment_id')->nullable()->index();
            // accepted | rejected (un rechazo se puede reintentar con la misma clave).
            $table->string('result', 16);
            $table->string('problem_code', 64)->nullable();
            $table->string('user_id')->nullable();
            $table->timestampTz('processed_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_scan_batch_items');
    }
};
