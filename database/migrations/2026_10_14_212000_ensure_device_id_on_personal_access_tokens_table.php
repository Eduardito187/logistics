<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Asegura la columna `device_id` en `personal_access_tokens` (P1-AUTH-01).
 *
 * La migración original `add_device_id` se aplicó cuando la tabla aún no existía (la base de Sanctum no
 * estaba publicada), por lo que quedó marcada como corrida sin haber agregado la columna. Ahora que la
 * tabla la crea `create_personal_access_tokens_table`, esta migración aditiva e idempotente agrega la
 * columna si falta, así las bases ya existentes quedan con el binding token↔dispositivo sin recrearlas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        if (Schema::hasColumn('personal_access_tokens', 'device_id')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->foreignId('device_id')
                ->nullable()
                ->after('id')
                ->constrained('devices')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        // No se revierte: la columna la gobierna la migración add_device_id original.
    }
};
