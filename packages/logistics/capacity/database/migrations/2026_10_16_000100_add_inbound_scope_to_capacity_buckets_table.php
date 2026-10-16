<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el eje de RECEPCIÓN (inbound) al cupo, espejo del eje de despacho (roadmap marketplace §5).
 * Todo ADITIVO: los cupos de despacho ya existentes quedan intactos.
 *
 *  - `scope_kind`: discriminador del eje del cupo. Default 'dispatch' (back-fill de las filas previas);
 *    'inbound' para los cupos de andén de un almacén central.
 *  - `warehouse_id`: almacén central del cupo de recepción (UUID de un nodo de network). NULL en despacho.
 *  - `dock_id`: andén concreto del cupo de recepción (opcional; NULL = capacidad de todo el almacén).
 *
 * Índices con guardas Schema::hasColumn para ser idempotente y no chocar si ya existieran.
 *
 * Clave natural del cupo inbound (índice único PARCIAL, solo PostgreSQL): un cupo por
 * (almacén, andén, fecha, franja, unidad) con scope_kind='inbound'. El andén puede ir NULL, y en
 * PostgreSQL los NULL se consideran distintos en un índice único, así que se normaliza con COALESCE a un
 * UUID centinela (nil) para que dos cupos del mismo almacén sin andén colisionen de verdad. Los índices
 * parciales de despacho (node/zone/municipality) no se tocan: sus filas inbound van con esas columnas en
 * NULL, así que no interfieren.
 */
return new class extends Migration
{
    private const string NIL_UUID = '00000000-0000-0000-0000-000000000000';

    public function up(): void
    {
        Schema::table('capacity_buckets', function (Blueprint $table): void {
            if (!Schema::hasColumn('capacity_buckets', 'scope_kind')) {
                $table->string('scope_kind')->default('dispatch')->after('id')->index();
            }
            if (!Schema::hasColumn('capacity_buckets', 'warehouse_id')) {
                $table->uuid('warehouse_id')->nullable()->after('municipality_id')->index();
            }
            if (!Schema::hasColumn('capacity_buckets', 'dock_id')) {
                $table->uuid('dock_id')->nullable()->after('warehouse_id')->index();
            }
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX IF NOT EXISTS capacity_buckets_inbound_natural_key '
            ."ON capacity_buckets (warehouse_id, COALESCE(dock_id, '".self::NIL_UUID."'), slot_date, time_slot, unit) "
            ."WHERE scope_kind = 'inbound'",
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS capacity_buckets_inbound_natural_key');
        }

        Schema::table('capacity_buckets', function (Blueprint $table): void {
            foreach (['dock_id', 'warehouse_id', 'scope_kind'] as $column) {
                if (Schema::hasColumn('capacity_buckets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
