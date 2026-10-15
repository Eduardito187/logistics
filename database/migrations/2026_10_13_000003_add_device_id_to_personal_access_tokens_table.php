<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ata cada token Sanctum a un dispositivo (P1-AUTH-01).
 *
 * La tabla `personal_access_tokens` la crea la migración que trae el propio paquete
 * Sanctum; aquí solo agregamos la columna de dispositivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('personal_access_tokens')) {
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
        if (!Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::table('personal_access_tokens', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('device_id');
        });
    }
};
