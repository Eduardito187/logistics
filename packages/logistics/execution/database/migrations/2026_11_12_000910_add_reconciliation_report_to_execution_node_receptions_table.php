<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Informe del operador al conciliar una recepción en nodo (aditivo e idempotente): notas, foto de
 * evidencia, motivo declarado de cada faltante y envío real de cada sobrante (jsonb).
 *
 * Además agrega el índice compuesto (destination_node_id, status, opened_at) que sirve el listado de
 * recepciones por nodo y estado de la app de tienda (GET /app/node-receptions?node_id=&status=open).
 */
return new class extends Migration
{
    private const string TABLE = 'execution_node_receptions';

    private const string LIST_INDEX = 'execution_node_receptions_dest_status_opened_index';

    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table): void {
            if (!Schema::hasColumn(self::TABLE, 'reconciliation_notes')) {
                $table->text('reconciliation_notes')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'reconciliation_photo_path')) {
                $table->string('reconciliation_photo_path', 1024)->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'missing_units')) {
                $table->jsonb('missing_units')->nullable();
            }
            if (!Schema::hasColumn(self::TABLE, 'surplus_units')) {
                $table->jsonb('surplus_units')->nullable();
            }
        });

        if (!Schema::hasIndex(self::TABLE, self::LIST_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index(['destination_node_id', 'status', 'opened_at'], self::LIST_INDEX);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex(self::TABLE, self::LIST_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::LIST_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            foreach (['reconciliation_notes', 'reconciliation_photo_path', 'missing_units', 'surplus_units'] as $column) {
                if (Schema::hasColumn(self::TABLE, $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
