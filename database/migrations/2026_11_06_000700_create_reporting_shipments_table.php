<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C10 — Proyección de reporting transversal (read-side de CQRS poblado por el outbox).
 *
 * Tabla DESNORMALIZADA de solo-lectura para el cockpit: una fila por envío con su estado y metadatos,
 * mantenida por un proyector que reacciona a los eventos de dominio que ya viajan por el outbox
 * (shipments.shipment_received/planned/cancelled). El objetivo es que la capa cockpit lea de ESTA
 * proyección en vez de hacer `DB::table('shipments')` + JOINs cross-context: así cada contexto queda
 * extraíble a microservicio sin tocar el cockpit (el único acoplamiento transversal que quedaba).
 *
 * Es la PRUEBA DE CONCEPTO del patrón (corte: envíos). Las demás lecturas del cockpit (ver
 * `app/Support/Cockpit/*`) se migran al mismo mecanismo agregando su proyección + proyector por evento.
 *
 * Aditiva e idempotente: solo crea la tabla; el proyector hace upsert por `shipment_id`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reporting_shipments', function (Blueprint $table): void {
            $table->uuid('shipment_id')->primary();
            $table->string('external_id')->nullable()->index();
            $table->string('source')->nullable();
            $table->string('status')->index();
            $table->integer('line_count')->nullable();
            $table->integer('unit_count')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_event_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reporting_shipments');
    }
};
