<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de escala para transportistas. Aditivos e idempotentes: solo aceleran lectura.
 *
 *  - carrier_tariffs (corridor_from, corridor_to, corridor_type, vehicle_class, price_cents): la
 *    cotización busca la tarifa más barata de un corredor para una clase de vehículo
 *    (`WHERE corridor_from, corridor_to, corridor_type, vehicle_class ORDER BY price_cents`). El índice
 *    existente (corridor_from, corridor_to, corridor_type) no incluye `vehicle_class` (siempre filtrada)
 *    ni `price_cents` (el orden); este compuesto cubre filtro + orden y evita ordenar en memoria.
 *  - carrier_settlements (period, carrier_id): el listado liquida por período ordenado
 *    `ORDER BY period DESC, carrier_id`, y el desglose de costos cruza por rango de `period`. El
 *    compuesto existente arranca por carrier_id, así que no sirve para ordenar/barrer por período; este
 *    sí lidera por period.
 *
 * Solo PostgreSQL (el motor del proyecto) soporta esta construcción idempotente; en otros drivers se omite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS carrier_tariffs_corridor_class_price_index '
            .'ON carrier_tariffs (corridor_from, corridor_to, corridor_type, vehicle_class, price_cents)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS carrier_settlements_period_carrier_index '
            .'ON carrier_settlements (period, carrier_id)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS carrier_tariffs_corridor_class_price_index');
        DB::statement('DROP INDEX IF EXISTS carrier_settlements_period_carrier_index');
    }
};
