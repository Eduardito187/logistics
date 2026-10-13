<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de escala para las promesas (frente E — escala). Aditivos: solo aceleran lectura.
 *
 *  - (status, reserved_until): el barrido de reservas vencidas (ExpireDueReservations) busca las
 *    promesas en estado Reserved con `reserved_until <= ahora`. El índice simple de `status` no acota
 *    el instante; el compuesto resuelve filtro + rango en una pasada, y corre cada minuto.
 *  - (zone_id, slot_date): consultas de consistencia y reportes de promesas por zona y día.
 *
 * `shipment_ref`, `zone_id` y `status` ya están indexados desde la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promises', function (Blueprint $table): void {
            $table->index(['status', 'reserved_until'], 'promises_status_reserved_until_index');
            $table->index(['zone_id', 'slot_date'], 'promises_zone_slot_date_index');
        });
    }

    public function down(): void
    {
        Schema::table('promises', function (Blueprint $table): void {
            $table->dropIndex('promises_status_reserved_until_index');
            $table->dropIndex('promises_zone_slot_date_index');
        });
    }
};
