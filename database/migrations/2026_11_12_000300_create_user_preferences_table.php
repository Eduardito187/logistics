<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferencias del usuario de la app interna (auditoría app #8): un documento JSON clave→valor por
 * usuario para que sobrevivan a una reinstalación y viajen entre teléfonos.
 *
 * `preferences` guarda los valores; `key_updated_at` guarda, por clave, el instante del último cambio
 * aceptado (last-write-wins por clave: un cambio más viejo que el guardado no lo pisa).
 *
 * Una fila por usuario (unique). Aditiva e idempotente (no recrea si ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_preferences')) {
            return;
        }

        Schema::create('user_preferences', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->jsonb('preferences');
            $table->jsonb('key_updated_at');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_preferences');
    }
};
