<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FALTANTES de la entrega en tienda: la tienda ya no recibe ni concilia mercadería desde la app, así que los
 * productos que el chofer NO pudo dejar los declara él mismo al registrar su entrega en tienda. Esta columna
 * guarda esa lista (`[{unit_id, reason}]`) junto a los demás datos de la entrega en tienda.
 *
 * Aditiva e idempotente: columna JSON nullable (null = dejó el pedido completo); no toca filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('execution_deliveries') || Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')) {
            return;
        }

        Schema::table('execution_deliveries', function (Blueprint $table): void {
            $table->jsonb('store_drop_missing_units')->nullable();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('execution_deliveries', 'store_drop_missing_units')) {
            Schema::table('execution_deliveries', function (Blueprint $table): void {
                $table->dropColumn('store_drop_missing_units');
            });
        }
    }
};
