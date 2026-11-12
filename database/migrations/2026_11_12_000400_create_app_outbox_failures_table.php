<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acciones offline de la app que el servidor rechazó de forma definitiva (o que agotaron reintentos),
 * reportadas por la propia app con `POST /app/outbox-failures` para que la torre de control las vea.
 *
 * Sin datos personales: la app no envía payload, `detail` ni nombres; usuario y dispositivo salen del
 * token. `client_id` es la `Idempotency-Key` (ULID) de la acción en el teléfono: único, así reenviar
 * el mismo lote no duplica. `resolved_at`/`resolved_by` los marca un supervisor desde el admin
 * (`resolved_by` es varchar: el id del actor del admin no siempre es un UUID).
 *
 * Aditiva e idempotente (no recrea si ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('app_outbox_failures')) {
            return;
        }

        Schema::create('app_outbox_failures', function (Blueprint $table): void {
            $table->id();
            $table->string('client_id', 64)->unique();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('device_id')->nullable()->constrained('devices')->nullOnDelete();
            // Cola local de origen (action | photo) y operación del cliente (recordLoadScan, pod, …).
            $table->string('queue', 20);
            $table->string('kind', 80);
            // rejected | retries_exhausted | invalid_payload | unknown_kind
            $table->string('reason', 40);
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('problem_code', 80)->nullable();
            // Endpoint relativo del intento (p. ej. "/app/shipments/{id}/scans"), sin query ni datos.
            $table->string('endpoint', 255)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            // Versión de la app que lo reportó (de la cabecera X-App-Version).
            $table->string('app_version', 40)->nullable();
            $table->timestamp('occurred_at');
            $table->timestamp('resolved_at')->nullable();
            $table->string('resolved_by')->nullable();
            $table->timestamps();

            $table->index(['occurred_at']);
            $table->index(['user_id', 'occurred_at']);
            $table->index(['device_id', 'occurred_at']);
            $table->index(['resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_outbox_failures');
    }
};
