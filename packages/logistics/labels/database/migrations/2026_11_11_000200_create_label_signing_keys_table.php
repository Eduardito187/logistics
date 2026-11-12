<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro APPEND-ONLY de claves públicas de firma de etiquetas por `key_id` (el campo "k" del QR).
 *
 * Invariante de integridad: una vez que un key_id firmó etiquetas con una pública, esa pública se
 * sirve PARA SIEMPRE por ese id, para que una etiqueta válida nunca se rechace. Rotar la clave de firma
 * = un key_id NUEVO (la vieja se sigue sirviendo por su id). Reusar un id para otra clave es el bug que
 * rompía la verificación; la guardia al firmar lo impide. Esta tabla es la memoria que detecta la colisión
 * y la fuente de claves históricas del bundle público.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_signing_keys')) {
            return;
        }

        Schema::create('label_signing_keys', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // key_id = campo "k" del QR; varias filas por id solo si convivieran públicas históricas.
            $table->unsignedInteger('key_id')->index();
            // Clave pública Ed25519 en base64 (nunca la privada).
            $table->string('public_key', 128);
            $table->timestampTz('created_at')->nullable();
            // Append-only e idempotente: el mismo par (id, pública) se registra una sola vez.
            $table->unique(['key_id', 'public_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_signing_keys');
    }
};
