<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momento en que un pedido con RETIRO EN TIENDA llegó a su tienda destino (el chofer lo dejó ahí:
 * `execution.shipment_dropped_at_store`). Con esta columna el seguimiento del cliente puede decir «ya está
 * en la tienda, puedes pasar a recogerlo» sin sumar un estado nuevo al ciclo de vida.
 *
 * Migración ADITIVA e IDEMPOTENTE (memoria no-destructive-db-ops): solo agrega la columna nullable. No
 * rellena datos ni lleva índice: se lee siempre junto a la fila del seguimiento (por token o por envío).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('tracking_shipments')) {
            return;
        }

        if (!Schema::hasColumn('tracking_shipments', 'at_store_at')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->timestampTz('at_store_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('tracking_shipments') && Schema::hasColumn('tracking_shipments', 'at_store_at')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->dropColumn('at_store_at');
            });
        }
    }
};
