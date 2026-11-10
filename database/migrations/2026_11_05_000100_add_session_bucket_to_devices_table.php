<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sesiones una-por-plataforma (estilo app de banco): cada dispositivo pertenece a un BUCKET de sesión
 * `web` o `app` (android+ios = app). El invariante es ≤1 sesión activa por bucket por usuario; al
 * iniciar sesión un segundo dispositivo del MISMO bucket, el anterior queda DESALOJADO (revocado con
 * motivo `session_superseded`) sin tocar el otro bucket.
 *
 * Aditivo y backward-compatible: columnas nullable + backfill del bucket a partir del `platform` ya
 * guardado, e índice para la consulta de desalojo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            // Bucket de sesión derivado del platform (o de un client_kind explícito en el login).
            $table->string('session_bucket')->nullable()->after('platform');
            // Motivo de revocación: distingue el desalojo por sesión nueva ('session_superseded') de una
            // baja normal (robo/extravío), para que el dispositivo desalojado reciba un 401 distinguible.
            $table->string('revoked_reason')->nullable()->after('revoked_at');
        });

        // Backfill del bucket para los dispositivos ya existentes: web si el platform es web/desktop,
        // app en cualquier otro caso (android/ios/desconocido).
        DB::table('devices')
            ->whereIn(DB::raw('lower(coalesce(platform, \'\'))'), ['web', 'desktop', 'browser'])
            ->update(['session_bucket' => 'web']);
        DB::table('devices')
            ->whereNull('session_bucket')
            ->update(['session_bucket' => 'app']);

        Schema::table('devices', function (Blueprint $table): void {
            // Consulta de desalojo: dispositivos activos del usuario en un bucket dado.
            $table->index(['user_id', 'session_bucket', 'revoked_at'], 'devices_user_bucket_revoked_index');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropIndex('devices_user_bucket_revoked_index');
            $table->dropColumn(['session_bucket', 'revoked_reason']);
        });
    }
};
