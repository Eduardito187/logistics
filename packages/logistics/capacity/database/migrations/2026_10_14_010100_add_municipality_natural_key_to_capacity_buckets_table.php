<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Recalcula los índices ÚNICOS PARCIALES de la clave natural del cupo tras sumar el eje municipio.
 * Aditivo: reemplaza los dos índices parciales previos (nodo/zona) por versiones que exigen que los
 * OTROS dos ejes de scope sean NULL, y agrega el índice parcial del eje municipio.
 *
 * Como en cada bucket EXACTAMENTE una columna de scope va poblada (municipality_id / zone_id / node_id)
 * y en PostgreSQL los NULL se consideran distintos en un índice único, la única forma de impedir de
 * verdad cupos duplicados por su clave natural es un índice parcial por tipo de scope, cada uno sobre su
 * columna no nula y filtrando a que las otras dos vayan NULL:
 *  - cupos de MUNICIPIO (node_id IS NULL AND zone_id IS NULL): único por (municipality_id, slot_date, time_slot, unit)
 *  - cupos de ZONA      (node_id IS NULL AND municipality_id IS NULL): único por (zone_id, slot_date, time_slot, unit)
 *  - cupos de NODO      (zone_id IS NULL AND municipality_id IS NULL): único por (node_id, slot_date, time_slot, unit)
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

        // Los parciales previos no contemplaban municipality_id; se recrean con la condición completa.
        DB::statement('DROP INDEX IF EXISTS capacity_buckets_node_natural_key');
        DB::statement('DROP INDEX IF EXISTS capacity_buckets_zone_natural_key');

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_node_natural_key '
            .'ON capacity_buckets (node_id, slot_date, time_slot, unit) '
            .'WHERE zone_id IS NULL AND municipality_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_zone_natural_key '
            .'ON capacity_buckets (zone_id, slot_date, time_slot, unit) '
            .'WHERE node_id IS NULL AND municipality_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_municipality_natural_key '
            .'ON capacity_buckets (municipality_id, slot_date, time_slot, unit) '
            .'WHERE node_id IS NULL AND zone_id IS NULL',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS capacity_buckets_municipality_natural_key');
        DB::statement('DROP INDEX IF EXISTS capacity_buckets_node_natural_key');
        DB::statement('DROP INDEX IF EXISTS capacity_buckets_zone_natural_key');

        // Restaura los parciales previos (nodo/zona sin el eje municipio).
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_node_natural_key '
            .'ON capacity_buckets (node_id, slot_date, time_slot, unit) WHERE zone_id IS NULL',
        );
        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_zone_natural_key '
            .'ON capacity_buckets (zone_id, slot_date, time_slot, unit) WHERE node_id IS NULL',
        );
    }
};
