<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Momento de ENTREGA del pedido en el read model del seguimiento (Fase 0 del roadmap de calificación):
 * sin esta columna solo existía `updated_at`, que cambia con cualquier guardado y no sirve para filtrar
 * "entregados en la ventana" con índice ni para contar el vencimiento del enlace público.
 *
 * Migración ADITIVA e IDEMPOTENTE (memoria no-destructive-db-ops): agrega la columna nullable y un índice
 * PARCIAL sobre las filas que la tienen (solo los entregados, una fracción de la tabla). No rellena datos:
 * el backfill es el comando `tracking:backfill-delivered-at` (idempotente, re-ejecutable).
 */
return new class extends Migration
{
    private const string INDEX = 'tracking_shipments_delivered_at_partial_idx';

    public function up(): void
    {
        if (!Schema::hasTable('tracking_shipments')) {
            return;
        }

        if (!Schema::hasColumn('tracking_shipments', 'delivered_at')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->timestampTz('delivered_at')->nullable();
            });
        }

        // Índice parcial (Postgres): solo filas entregadas. IF NOT EXISTS lo hace re-ejecutable.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS '.self::INDEX
                .' ON tracking_shipments (delivered_at) WHERE delivered_at IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }

        if (Schema::hasTable('tracking_shipments') && Schema::hasColumn('tracking_shipments', 'delivered_at')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->dropColumn('delivered_at');
            });
        }
    }
};
