<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ensancha `geocoding_cache.query_hash` de varchar(40) a varchar(64): la clave de dedup pasó de SHA-1
 * (40 hex) a SHA-256 (64 hex) para no usar un algoritmo de hash débil. Aditivo e idempotente.
 *
 * Nota: las filas previas con clave SHA-1 quedan como entradas inalcanzables (nunca coinciden con una
 * clave SHA-256); al ser un caché de dedup, un miss simplemente re-geocodifica y escribe la fila nueva.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasTable('geocoding_cache')) {
            return;
        }

        DB::statement('ALTER TABLE geocoding_cache ALTER COLUMN query_hash TYPE varchar(64)');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || !Schema::hasTable('geocoding_cache')) {
            return;
        }

        DB::statement('ALTER TABLE geocoding_cache ALTER COLUMN query_hash TYPE varchar(40)');
    }
};
