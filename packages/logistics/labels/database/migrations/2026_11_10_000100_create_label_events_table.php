<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de CICLO DE VIDA de las etiquetas (auditoría): quién GENERÓ, IMPRIMIÓ o REVOCÓ, y cuándo. Los
 * ESCANEOS ya viven en `execution_scan_events` (con su actor y etapa) y se unen por bulto en la lectura del
 * timeline; esta tabla cubre los eventos de emisión/impresión/revocación que no estaban trazados por actor.
 *
 * Append-only; aditiva e idempotente (no toca `labels_emitted`).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_events')) {
            return;
        }

        Schema::create('label_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Etiqueta afectada (label_id de negocio) y, para eventos por pedido (p. ej. impresión en lote),
            // el número de pedido.
            $table->string('label_id')->index();
            $table->string('order_id')->nullable()->index();
            // Evento: generated | printed | revoked.
            $table->string('event', 20);
            // Actor: identidad (email/id) y nombre legible del usuario que hizo la acción ('system' si fue
            // automático, p. ej. emisión por el outbox sin usuario).
            $table->string('actor')->nullable();
            $table->string('actor_name')->nullable();
            // Datos extra del evento (motivo de revocación, tipo de impresora, etc.).
            $table->jsonb('meta')->nullable();
            $table->timestampTz('occurred_at')->index();
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_events');
    }
};
