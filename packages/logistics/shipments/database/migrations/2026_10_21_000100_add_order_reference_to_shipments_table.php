<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Referencia de ORDEN del envío (multishipping): agrupa los envíos "hermanos" que nacen de una misma
 * orden pero van a destinos distintos (unos a domicilio, otros a retiro en tienda/nodo).
 *
 * El `external_id` sigue siendo la clave de idempotencia ÚNICA por fuente (un valor por envío), así que
 * los hermanos llevan externals distintos; `order_reference` los une bajo la orden original. En el flujo
 * de un solo destino `order_reference == external_id` (relleno por defecto), por lo que nada cambia.
 *
 * Aditivo, NULLABLE e idempotente: solo crea columna/índice si faltan, y rellena los existentes con su
 * propio `external_id` (backfill sin tocar datos ajenos). Reejecutar no falla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (!Schema::hasColumn('shipments', 'order_reference')) {
                $table->string('order_reference')->nullable()->after('external_id');
            }
        });

        // Backfill: los envíos previos son de un solo destino → su orden es su propio external_id.
        DB::table('shipments')->whereNull('order_reference')->update([
            'order_reference' => DB::raw('external_id'),
        ]);

        Schema::table('shipments', function (Blueprint $table): void {
            $indexes = Schema::getConnection()->getSchemaBuilder()->getIndexes('shipments');
            $hasIndex = collect($indexes)->contains(fn (array $i): bool => $i['name'] === 'shipments_order_reference_index');
            if (!$hasIndex) {
                $table->index('order_reference');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasColumn('shipments', 'order_reference')) {
                $table->dropColumn('order_reference');
            }
        });
    }
};
