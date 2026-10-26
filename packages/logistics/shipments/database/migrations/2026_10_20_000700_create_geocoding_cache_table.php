<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Caché/dedup PERSISTENTE de geocodificación (dirección → coordenada) del contexto Shipments.
 *
 * Normaliza la repetición más cara a escala: hoy, cada vez que se geocodifica la MISMA dirección
 * (reprocesos masivos, pedidos del mismo cliente, re-geocodificaciones) se repegaba a Nominatim/OSM.
 * Esta tabla deduplica por un hash de la consulta NORMALIZADA (trim + minúsculas + colapso de espacios
 * + proveedor/país): la primera resolución persiste el resultado y las siguientes se sirven desde la
 * fila, sin I/O externo. `hits`/`last_hit_at` miden cuánta repetición elimina.
 *
 * Vive en Shipments (donde está el contrato Geocoder). Otro contexto que quiera reusar geocoding lo
 * pide por un contrato, nunca compartiendo esta tabla (autonomía de bounded contexts).
 *
 * Aditiva e idempotente: solo crea la tabla si no existe; reejecutarla no falla y no toca otras tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('geocoding_cache')) {
            return;
        }

        Schema::create('geocoding_cache', function (Blueprint $table): void {
            // Clave natural y dedup: sha1 de (proveedor|país|consulta normalizada). Es la PK: única por
            // definición y el índice que resuelve el lookup caliente de una sola pasada.
            $table->string('query_hash', 40)->primary();

            // Consulta normalizada tal como entró al hash (auditoría y depuración del agrupamiento).
            $table->text('normalized_query');

            // Proveedor que resolvió (nominatim, ...): distingue cachés de distintos geocoders.
            $table->string('provider', 32);

            // Coordenada resuelta (nullable: el proveedor pudo no ubicar la dirección). Mismo tipo/escala
            // que shipments.lat/lng para no perder precisión.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Tipo de coincidencia (EXACT/INTERPOLATED/APPROXIMATE/NONE) y score de confianza 0..100.
            $table->string('match_type', 16);
            $table->unsignedTinyInteger('confidence_score');

            // Forma primitiva completa del resultado (regla #7: se cachea el array, nunca el objeto de
            // dominio). Reservada para enriquecer el resultado sin re-geocodificar si el esquema crece.
            $table->jsonb('payload')->nullable();

            // Métrica de repetición: aciertos acumulados y último acierto.
            $table->unsignedInteger('hits')->default(1);

            $table->timestamp('created_at');
            $table->timestamp('last_hit_at');

            // Lookups por proveedor (p. ej. purgar/medir una caché concreta).
            $table->index('provider', 'geocoding_cache_provider_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geocoding_cache');
    }
};
