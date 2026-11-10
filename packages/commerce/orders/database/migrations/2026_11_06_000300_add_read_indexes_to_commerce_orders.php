<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de LECTURA para `commerce_orders` (Ola 1 del roadmap de escalabilidad). La tabla se creó solo con
 * índices en `customer_id` y `status`, pero los read-services del cockpit la leen por el NOMBRE del cliente
 * (extraído del JSON `ship_to`) y por `created_at`:
 *   - CustomerHub (Cliente 360): `ship_to->>'customer_name' = ?` + `order by created_at desc`.
 *   - GlobalSearch (⌘K) y AttentionQueues (pedidos pagados sin envío): `status='paid'` + `order by created_at desc`.
 *
 * Sin estos índices, a millones de pedidos cada apertura de Cliente 360 / ⌘K / cola de triage hace seq scan +
 * sort. Se agregan:
 *   - índice de EXPRESIÓN sobre `(ship_to->>'customer_name')` (btree; cubre la igualdad exacta de CustomerHub),
 *   - índice en `created_at` (ordenamiento por recencia),
 *   - índice compuesto `(status, created_at)` (cola de pagados sin envío).
 *
 * `CONCURRENTLY` + `IF NOT EXISTS` → aditivo, idempotente y sin bloquear la tabla en producción. Por eso la
 * migración corre FUERA de transacción (`$withinTransaction = false`).
 *
 * Nota de roadmap: a futuro conviene DENORMALIZAR `customer_name` a una columna real (como ya se hizo en
 * `shipments`) y la búsqueda por `ilike` usa el índice trigram GIN que agrega la migración siguiente.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (!Schema::hasTable('commerce_orders')) {
            return;
        }

        DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS commerce_orders_customer_name_idx ON commerce_orders ((ship_to->>'customer_name'))");
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS commerce_orders_created_at_idx ON commerce_orders (created_at)');
        DB::statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS commerce_orders_status_created_at_idx ON commerce_orders (status, created_at)');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS commerce_orders_customer_name_idx');
        DB::statement('DROP INDEX IF EXISTS commerce_orders_created_at_idx');
        DB::statement('DROP INDEX IF EXISTS commerce_orders_status_created_at_idx');
    }
};
