<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ENTREGA EN TIENDA del chofer: el camión deja en la tienda un pedido que el cliente va a retirar ahí (o un
 * traspaso). No es la entrega al cliente: la entrega sigue abierta hasta que la tienda se lo entrega en el
 * mostrador. Estas columnas guardan cuándo y en qué tienda lo dejó el chofer (y la foto), que es lo que
 * marca el pedido como «listo para retirar». Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('execution_deliveries')) {
            return;
        }

        Schema::table('execution_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_deliveries', 'store_dropped_at')) {
                $table->timestampTz('store_dropped_at')->nullable()->index();
            }
            if (!Schema::hasColumn('execution_deliveries', 'store_drop_node_id')) {
                $table->uuid('store_drop_node_id')->nullable();
            }
            if (!Schema::hasColumn('execution_deliveries', 'store_drop_photo_path')) {
                $table->string('store_drop_photo_path', 1024)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['store_dropped_at', 'store_drop_node_id', 'store_drop_photo_path'] as $column) {
            if (Schema::hasColumn('execution_deliveries', $column)) {
                Schema::table('execution_deliveries', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
