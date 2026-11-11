<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El seguimiento pasa a ser el ciclo de vida del PEDIDO (no solo del envío): nace al crear la orden por
 * `order_reference`, antes de que exista el envío, por lo que `shipment_id` pasa a ser NULLABLE y se liga
 * después. Migración ADITIVA e IDEMPOTENTE (ver memoria no-destructive-db-ops): agrega la columna, afloja
 * la nulabilidad y reescribe los valores de estado viejos al vocabulario nuevo. No borra datos.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('tracking_shipments', 'order_reference')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->string('order_reference')->nullable()->after('shipment_id')->index();
            });
        }

        // El envío se liga después del pago: shipment_id deja de ser obligatorio. En Postgres el índice
        // único admite varios NULL, así que varios seguimientos "pendientes" conviven sin chocar.
        Schema::table('tracking_shipments', function (Blueprint $table): void {
            $table->uuid('shipment_id')->nullable()->change();
        });

        // Backfill del vocabulario viejo al nuevo (ver VisibleStatus): REGISTERED→PROCESSING,
        // PREPARED→IN_PROGRESS, ARRIVING→ON_THE_WAY. El resto (ON_THE_WAY/DELIVERED/FAILED) no cambia.
        DB::table('tracking_shipments')->where('status', 'REGISTERED')->update(['status' => 'PROCESSING']);
        DB::table('tracking_shipments')->where('status', 'PREPARED')->update(['status' => 'IN_PROGRESS']);
        DB::table('tracking_shipments')->where('status', 'ARRIVING')->update(['status' => 'ON_THE_WAY']);

        // Backfill de order_reference desde el envío (external_id = orderId, o orderId#n:nodeId en retiro:
        // se queda con la parte antes de '#n:'). Solo filas sin referencia y con envío ligado.
        if (Schema::hasTable('shipments')) {
            DB::statement(<<<'SQL'
                UPDATE tracking_shipments AS t
                SET order_reference = split_part(s.external_id, '#n:', 1)
                FROM shipments AS s
                WHERE t.shipment_id = s.id
                  AND t.order_reference IS NULL
            SQL);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('tracking_shipments', 'order_reference')) {
            Schema::table('tracking_shipments', function (Blueprint $table): void {
                $table->dropColumn('order_reference');
            });
        }
    }
};
