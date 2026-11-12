<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prerrequisito de la calificación de envío (Ola 0, módulo 1 del roadmap): el evento de entrega no trae el
 * email del cliente, así que el seguimiento lo copia del lado lectura de shipments al proyectar la entrega
 * (`customer_email`) y anota cuándo se pidió la calificación (`rating_requested_at`) para no invitar dos
 * veces. `customer_email` es PII: nunca sale por la página pública ni por la API de canales.
 *
 * Migración ADITIVA e IDEMPOTENTE (memoria no-destructive-db-ops): columnas nullable y un índice PARCIAL
 * sobre los candidatos (entregados con email y sin invitación), que el cron horario barre por `delivered_at`.
 */
return new class extends Migration
{
    private const string INDEX = 'tracking_shipments_awaiting_rating_partial_idx';

    public function up(): void
    {
        if (!Schema::hasTable('tracking_shipments')) {
            return;
        }

        if (!Schema::hasColumn('tracking_shipments', 'customer_email')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->string('customer_email')->nullable();
            });
        }

        if (!Schema::hasColumn('tracking_shipments', 'rating_requested_at')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->timestampTz('rating_requested_at')->nullable();
            });
        }

        // Índice parcial (Postgres): solo los candidatos pendientes. IF NOT EXISTS lo hace re-ejecutable.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'CREATE INDEX IF NOT EXISTS '.self::INDEX
                .' ON tracking_shipments (delivered_at)'
                .' WHERE rating_requested_at IS NULL AND customer_email IS NOT NULL',
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS '.self::INDEX);
        }

        if (!Schema::hasTable('tracking_shipments')) {
            return;
        }

        foreach (['customer_email', 'rating_requested_at'] as $column) {
            if (Schema::hasColumn('tracking_shipments', $column)) {
                Schema::table('tracking_shipments', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
