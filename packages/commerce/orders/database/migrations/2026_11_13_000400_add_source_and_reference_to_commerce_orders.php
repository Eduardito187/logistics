<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Origen y referencia externa de la orden, para soportar el backfill de órdenes a partir de los envíos
 * existentes (pedidos que entraron como `shipments` por seeders y nunca pasaron por el checkout).
 *
 * - `source`: de dónde nació la orden. 'checkout' para las creadas por el flujo comercial normal (default,
 *   no altera las existentes) y 'backfill' para las reconstruidas desde envíos. Permite distinguirlas.
 * - `order_reference`: clave de agrupación externa usada por el backfill (el `order_reference` del envío en
 *   multishipping, o su `external_id`/id si no lo tiene). Hace el backfill idempotente: una referencia ya
 *   presente no se recrea.
 *
 * Migración ADITIVA e idempotente (ver memoria no-destructive-db-ops): guardia por columna para poder
 * reejecutarla sin romper.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commerce_orders', 'source')) {
            return;
        }

        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('source')->default('checkout')->index()->after('status');
            $table->string('order_reference')->nullable()->index()->after('source');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('commerce_orders', 'source')) {
            return;
        }

        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropColumn(['source', 'order_reference']);
        });
    }
};
