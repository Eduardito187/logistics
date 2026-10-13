<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla del patrón Transactional Outbox: mensajes salientes hacia sistemas externos (SAP, FCM, etc.).
 *
 * El índice único sobre event_id da la idempotencia del append; el índice compuesto
 * (status, available_at) sirve al pull del relay (FOR UPDATE SKIP LOCKED).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outbox_messages', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('aggregate_type');
            $table->string('aggregate_id')->index();
            $table->string('event_name')->index();
            $table->jsonb('payload');
            $table->string('status')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('available_at')->index();
            $table->timestampTz('created_at');
            $table->timestampTz('dispatched_at')->nullable();
            $table->text('last_error')->nullable();

            // Pull del relay: filtra por estado despachable y ordena por disponibilidad.
            $table->index(['status', 'available_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_messages');
    }
};
