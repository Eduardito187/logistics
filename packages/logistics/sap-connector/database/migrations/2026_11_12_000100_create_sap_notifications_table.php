<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos fire-and-forget a SAP de los hechos de despacho (registro de nuestro lado + respuesta cruda).
 *
 * Aditiva e idempotente (guardia hasTable): no toca ninguna tabla existente. El índice único sobre
 * `event_id` da la idempotencia (un hecho → un aviso, insert-or-ignore). El índice (status,
 * next_attempt_at) sirve al barrido de reintentos, que solo mira Pending/Failed con el backoff vencido.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sap_notifications')) {
            return;
        }

        Schema::create('sap_notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('event_name', 120)->index();
            $table->string('aggregate_id', 120)->nullable();
            $table->jsonb('payload');
            $table->timestampTz('occurred_at')->nullable();
            // pending | sent | failed | skipped
            $table->string('status', 16);
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->unsignedSmallInteger('response_status')->nullable();
            // Respuesta cruda de SAP truncada a ~4 KB (diagnóstico).
            $table->text('response_body')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('next_attempt_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'next_attempt_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_notifications');
    }
};
