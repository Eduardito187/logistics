<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo CONFIGURABLE de motivos de "no se pudo entregar". PK uuid string no incremental (UUID v7 del
 * dominio). El `code` (slug en MAYÚSCULAS) es la clave natural estable del contrato con la app.
 *
 * Aditiva e idempotente: solo crea la tabla si no existe; no toca datos existentes. DELETE = desactivar
 * (bandera `active`), nunca borrado físico.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('execution_failure_reasons')) {
            return;
        }

        Schema::create('execution_failure_reasons', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('label');
            $table->boolean('active')->default(true);
            $table->boolean('requires_photo')->default(false);
            $table->boolean('requires_note')->default(false);
            $table->string('note_label')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            // La app pide los activos ordenados; el admin lista todos ordenados.
            $table->index(['active', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_failure_reasons');
    }
};
