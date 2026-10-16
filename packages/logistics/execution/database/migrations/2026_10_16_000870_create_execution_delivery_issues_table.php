<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de inconvenientes de entrega reportados por el chofer durante una entrega EN CURSO (append-only).
 * PK uuid string no incremental (UUID v7 del dominio). Es un hecho inmutable: solo se inserta.
 *
 * Distinto de la entrega fallida (que cierra la entrega) y del incidente del camión (otra tabla): aquí la
 * entrega sigue, solo se deja constancia del obstáculo (retraso, acceso, pedido del cliente, dirección
 * poco clara, otro) con su nota, GPS y foto opcional.
 *
 * Aditiva e idempotente: solo crea la tabla si no existe; no toca datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_delivery_issues')) {
            return;
        }

        Schema::create('execution_delivery_issues', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('type')->index();
            $table->text('note')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('photo_path', 2048)->nullable();
            $table->string('reported_by_user')->nullable();
            $table->string('reported_by_device')->nullable();
            $table->timestampTz('occurred_at');
            // Clave de idempotencia de la app offline del chofer: el reenvío del mismo reporte no duplica.
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_delivery_issues');
    }
};
