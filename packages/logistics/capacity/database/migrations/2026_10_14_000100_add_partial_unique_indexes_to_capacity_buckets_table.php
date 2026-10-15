<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Índices ÚNICOS PARCIALES para la clave natural del cupo (scope + fecha + franja + unidad). Aditivos.
 *
 * El único compuesto que ya existe —(node_id, zone_id, slot_date, time_slot, unit)— NO evita filas
 * duplicadas: en PostgreSQL los NULL se consideran distintos en un índice único, y en cada bucket
 * EXACTAMENTE una de las columnas de scope (node_id / zone_id) va NULL. Por eso dos cupos de la misma
 * zona (node_id NULL) o del mismo nodo (zone_id NULL) con igual fecha/franja/unidad pasan el único y se
 * insertan por duplicado. DefineCapacity hace comprobar-y-insertar (findBucket + save) sin transacción
 * atómica del lado de escritura, así que bajo concurrencia dos requests pueden crear el mismo cupo; este
 * índice es el backstop de la base que lo impide de verdad.
 *
 * Se resuelve con dos índices parciales, uno por tipo de scope, cada uno sobre su columna no nula:
 *  - cupos de NODO  (zone_id IS NULL): único por (node_id, slot_date, time_slot, unit)
 *  - cupos de ZONA  (node_id IS NULL): único por (zone_id, slot_date, time_slot, unit)
 *
 * Solo PostgreSQL (el motor del proyecto) soporta índices parciales; en otros drivers se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_node_natural_key '
            .'ON capacity_buckets (node_id, slot_date, time_slot, unit) WHERE zone_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_zone_natural_key '
            .'ON capacity_buckets (zone_id, slot_date, time_slot, unit) WHERE node_id IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::table('capacity_buckets', function (): void {
            DB::statement('DROP INDEX IF EXISTS capacity_buckets_node_natural_key');
            DB::statement('DROP INDEX IF EXISTS capacity_buckets_zone_natural_key');
        });
    }
};
