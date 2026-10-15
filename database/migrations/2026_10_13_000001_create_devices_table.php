<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos de la app interna: binding usuario↔dispositivo, revocación y última
 * actividad (P1-AUTH-01).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            // El binding con el usuario puede estar vacío hasta el primer login del equipo.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Huella estable del equipo que envía la app (identifica al dispositivo).
            $table->string('fingerprint')->unique();
            $table->string('platform')->nullable();
            $table->string('name')->nullable();
            $table->string('push_token')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
