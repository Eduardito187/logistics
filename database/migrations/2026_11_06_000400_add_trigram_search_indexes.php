<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de búsqueda TRIGRAM (Ola 1 del roadmap de escalabilidad). El command palette (⌘K, GlobalSearch) y
 * los directorios (Cliente / Personas) buscan con `ilike '%término%'` — el comodín a la izquierda inhabilita
 * cualquier índice btree, así que a millones de filas cada pulsación hace seq scan por fuente (el peor
 * hot-path interactivo).
 *
 * Solución estándar en PostgreSQL: la extensión `pg_trgm` + índices `GIN (columna gin_trgm_ops)`, que SÍ
 * aceleran `ilike '%...%'`. Se indexan las columnas que realmente se buscan en GlobalSearch y en los dos
 * directorios. Aditivo e idempotente (`IF NOT EXISTS`), `CONCURRENTLY` y fuera de transacción para no
 * bloquear en producción. Tolerante: si no se puede instalar la extensión (privilegios), no rompe la
 * migración — la búsqueda sigue funcionando (solo más lenta) hasta que un DBA habilite `pg_trgm`.
 *
 * App-level (no de un bounded context) porque cruza varias tablas de varios contextos (es infraestructura de
 * la capa de lectura del cockpit).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Índices trigram a crear: [nombre, tabla, expresión-indexada, columna-a-verificar].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: ?string}>
     */
    private array $indexes = [
        ['shipments_customer_name_trgm', 'shipments', 'customer_name', 'customer_name'],
        ['shipments_external_id_trgm', 'shipments', 'external_id', 'external_id'],
        ['shipments_customer_phone_trgm', 'shipments', 'customer_phone', 'customer_phone'],
        ['fleet_vehicles_plate_trgm', 'fleet_vehicles', 'plate', 'plate'],
        ['fleet_drivers_name_trgm', 'fleet_drivers', 'name', 'name'],
        ['fleet_helpers_name_trgm', 'fleet_helpers', 'name', 'name'],
        ['carriers_name_trgm', 'carriers', 'name', 'name'],
        ['network_nodes_name_trgm', 'network_nodes', 'name', 'name'],
        ['users_name_trgm', 'users', 'name', 'name'],
        ['users_email_trgm', 'users', 'email', 'email'],
        // Nombre del cliente dentro del JSON de la orden (misma expresión que indexa CustomerHub/GlobalSearch).
        ['commerce_orders_customer_name_trgm', 'commerce_orders', "(ship_to->>'customer_name')", null],
    ];

    public function up(): void
    {
        try {
            DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        } catch (Throwable) {
            // Sin privilegios para instalar la extensión: se omiten los índices trigram (la búsqueda sigue,
            // solo más lenta). Un DBA puede habilitar pg_trgm y re-ejecutar la migración.
            return;
        }

        foreach ($this->indexes as [$name, $table, $expression, $column]) {
            if (!Schema::hasTable($table)) {
                continue;
            }
            if ($column !== null && !Schema::hasColumn($table, $column)) {
                continue;
            }

            try {
                DB::statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$name} ON {$table} USING gin ({$expression} gin_trgm_ops)");
            } catch (Throwable) {
                // índice individual no creado (p. ej. tipo incompatible): no rompe el resto.
            }
        }
    }

    public function down(): void
    {
        foreach ($this->indexes as [$name]) {
            DB::statement("DROP INDEX IF EXISTS {$name}");
        }
    }
};
