<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de respuestas de la encuesta de satisfacción post-entrega (K-06).
 *
 * UNA respuesta por envío: shipment_id es único (respalda la regla de idempotencia del dominio). El
 * token deja la pista del enlace por el que se respondió (scope), sin PII. La calificación es un entero
 * 1-5; el comentario y el motivo son opcionales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_satisfaction_surveys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->unique();
            $table->string('token');
            $table->unsignedSmallInteger('rating')->index();
            $table->text('comment')->nullable();
            $table->string('reason')->nullable();
            $table->timestamps();
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_satisfaction_surveys');
    }
};
