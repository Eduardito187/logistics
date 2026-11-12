<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última IP desde la que se usó el dispositivo (auditoría app #28): ayuda al usuario a reconocer sus
 * equipos y a detectar uno que no es suyo en `GET /app/devices`. La llena la guarda de la puerta app
 * (`EnsureDeviceToken`) en el MISMO UPDATE ventaneado de `last_seen_at`, sin escrituras extra.
 *
 * ADITIVA, nullable e idempotente. 45 caracteres alcanzan para IPv6 completa (incluida la forma mapeada
 * de IPv4). No hay fuente GeoIP en el proyecto, así que no se guarda ciudad.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('devices') || Schema::hasColumn('devices', 'last_ip')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            $table->string('last_ip', 45)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('devices') || !Schema::hasColumn('devices', 'last_ip')) {
            return;
        }

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('last_ip');
        });
    }
};
