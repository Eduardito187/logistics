<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos de ALCANCE del envío: nodo y departamento de ORIGEN y departamento de DESTINO.
 *
 * El alcance por rol de la app (un preparador opera los pedidos cuyo origen o destino es su ciudad; una
 * tienda, los que salen de su nodo o llegan a él) se filtra en el servidor con estas columnas indexadas,
 * sin derivar el origen en cada lectura. Los códigos de departamento son los de `network_nodes.department`
 * (`la_paz`, `santa_cruz`, …). Se completan al guardar cada envío y, para los existentes, con
 * `logistics:shipments:backfill-scope`.
 *
 * Aditiva e idempotente: cada columna e índice se crea solo si falta. Columnas anulables sin valor por
 * defecto (no reescriben la tabla); un envío sin estos datos se sigue mostrando.
 */
return new class extends Migration
{
    private const array INDEXES = [
        'origin_node_id' => 'shipments_origin_node_id_index',
        'origin_department' => 'shipments_origin_department_index',
        'destination_department' => 'shipments_destination_department_index',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            if (!Schema::hasColumn('shipments', 'origin_node_id')) {
                $table->uuid('origin_node_id')->nullable();
            }
            if (!Schema::hasColumn('shipments', 'origin_department')) {
                $table->string('origin_department', 40)->nullable();
            }
            if (!Schema::hasColumn('shipments', 'destination_department')) {
                $table->string('destination_department', 40)->nullable();
            }
        });

        Schema::table('shipments', function (Blueprint $table): void {
            foreach (self::INDEXES as $column => $index) {
                if (!Schema::hasIndex('shipments', $index)) {
                    $table->index($column, $index);
                }
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipments')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            foreach (self::INDEXES as $column => $index) {
                if (Schema::hasIndex('shipments', $index)) {
                    $table->dropIndex($index);
                }
            }
        });

        Schema::table('shipments', function (Blueprint $table): void {
            foreach (array_keys(self::INDEXES) as $column) {
                if (Schema::hasColumn('shipments', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
