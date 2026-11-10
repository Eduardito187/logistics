<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evidencias de entrega LIGADAS AL CLIENTE (anti-fraude): firma, foto de documento, prueba de entrega y
 * daño. PK uuid string (UUID v7 del dominio). Lleva el HASH SHA-256 del archivo (integridad: una evidencia
 * no se puede intercambiar por otra) y la auditoría inmutable de captura (quién/cuándo/GPS), más el
 * snapshot de la identidad del cliente contra el que se valida y el estado de validación anti-fraude.
 *
 * La validación es señal, no bloqueo: `validation_status` ∈ pending|ok|review|mismatch, la escribe un
 * validador asíncrono on-premise. Aditiva e idempotente: solo crea la tabla si no existe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_delivery_evidence')) {
            return;
        }

        Schema::create('execution_delivery_evidence', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('kind')->index();

            // Snapshot inmutable de la identidad del cliente al momento de la entrega (contra quién se valida).
            $table->string('customer_id')->nullable()->index();
            $table->string('customer_document_id')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('customer_phone')->nullable();

            // Referencia durable del archivo + hash de integridad.
            $table->string('storage_disk');
            $table->string('storage_path', 2048);
            $table->string('sha256', 64)->index();

            // Auditoría inmutable de captura.
            $table->string('captured_by_user')->nullable();
            $table->string('captured_by_device')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestampTz('captured_at');
            $table->string('idempotency_key')->nullable()->unique();

            // Estado de validación anti-fraude (lo escribe el validador asíncrono).
            $table->string('validation_status')->default('pending')->index();
            $table->text('validation_detail')->nullable();
            $table->timestampTz('validated_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_delivery_evidence');
    }
};
