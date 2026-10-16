<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensión de PROPIEDAD/fulfillment (ADR 017), aditiva sobre `shipments` y `shipment_units`.
 *
 * Tres columnas que viajan con el envío y la unidad física (bulto):
 * - `owner_party_id`   (uuid, NULLABLE): el seller dueño del producto; null = Dismac (retail).
 * - `fulfillment_mode` (string, NOT NULL, default 'owned'): owned (retail) | fbd | dropship.
 * - `seller_sku_ref`   (string, NULLABLE): el SKU del seller, opcional.
 *
 * Back-fill IMPLÍCITO por el default: toda fila existente queda `owned` con dueño null, así el flujo
 * retail no cambia. Índices por `fulfillment_mode` (y por `owner_party_id`) pensando en millones de filas:
 * segmentar/filtrar por modo y por dueño sin escanear la tabla.
 *
 * Idempotente: solo agrega columna/índice si no existen (reejecutar la migración no falla). Nunca
 * migrate:fresh/wipe: solo `migrate`.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['shipments', 'shipment_units'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                if (!Schema::hasColumn($tableName, 'owner_party_id')) {
                    $table->uuid('owner_party_id')->nullable()->index()->after('id');
                }

                if (!Schema::hasColumn($tableName, 'fulfillment_mode')) {
                    $table->string('fulfillment_mode')->default('owned')->index()->after('owner_party_id');
                }

                if (!Schema::hasColumn($tableName, 'seller_sku_ref')) {
                    $table->string('seller_sku_ref')->nullable()->after('fulfillment_mode');
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['shipments', 'shipment_units'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName): void {
                foreach (['owner_party_id', 'fulfillment_mode', 'seller_sku_ref'] as $column) {
                    if (Schema::hasColumn($tableName, $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
