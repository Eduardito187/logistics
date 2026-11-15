<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIERRE CON FALTANTES de un traspaso: la entrega queda entregada aunque no hayan llegado todos los productos,
 * porque la tienda concilió la recepción con diferencias o porque la oficina lo cerró a mano. Estas columnas
 * dejan constancia en la entrega:
 *
 *  - `closed_with_shortage`: se cerró dejando faltantes documentados;
 *  - `shortage_closed_at`: cuándo;
 *  - `shortage_close_reason` / `shortage_closed_by`: motivo y usuario del cierre manual de oficina (null si lo
 *    cerró la conciliación de la tienda). El actor es texto: guarda el id de la cuenta tal como llega.
 *
 * Aditiva e idempotente: columnas nuevas con default o nullable; no toca filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('execution_deliveries')) {
            return;
        }

        Schema::table('execution_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_deliveries', 'closed_with_shortage')) {
                $table->boolean('closed_with_shortage')->default(false);
            }
            if (!Schema::hasColumn('execution_deliveries', 'shortage_closed_at')) {
                $table->timestampTz('shortage_closed_at')->nullable();
            }
            if (!Schema::hasColumn('execution_deliveries', 'shortage_close_reason')) {
                $table->text('shortage_close_reason')->nullable();
            }
            if (!Schema::hasColumn('execution_deliveries', 'shortage_closed_by')) {
                $table->string('shortage_closed_by', 191)->nullable();
            }
        });
    }

    public function down(): void
    {
        foreach (['closed_with_shortage', 'shortage_closed_at', 'shortage_close_reason', 'shortage_closed_by'] as $column) {
            if (Schema::hasColumn('execution_deliveries', $column)) {
                Schema::table('execution_deliveries', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
