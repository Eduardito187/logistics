<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Índices de escala para la operación de flota propia (costos/TCO). Aditivos e idempotentes.
 *
 *  - fleetops_fuel_prices (fuel_type, effective_from): el precio vigente se busca por tipo de
 *    combustible con `effective_from <= :fecha ORDER BY effective_from DESC` (lookup caliente en cada
 *    cálculo de costo). Había índices sueltos de fuel_type y effective_from; el compuesto cubre el
 *    filtro por tipo y el orden descendente por vigencia en una pasada.
 *  - fleetops_refuels (vehicle_id, refueled_at): las recargas de un vehículo se listan y acotan por
 *    rango de fecha ordenadas por `refueled_at DESC` (consumo entre llenados). Los índices sueltos no
 *    combinan vehículo + fecha; este compuesto sí.
 *  - fleetops_maintenance_orders (vehicle_id, scheduled_at): el historial de mantenimiento por vehículo
 *    ordena por `scheduled_at DESC`. `scheduled_at` no estaba indexado pese a ser el orden por defecto.
 *  - fleetops_expenses (vehicle_id, incurred_at): el desglose de gastos y las sumas por vehículo filtran
 *    por vehículo + rango de `incurred_at` y ordenan por `incurred_at DESC`. Los índices sueltos no
 *    combinan ambas columnas; este compuesto soporta el desglose de costo por km / TCO.
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
            'CREATE INDEX IF NOT EXISTS fleetops_fuel_prices_type_effective_index '
            .'ON fleetops_fuel_prices (fuel_type, effective_from)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS fleetops_refuels_vehicle_refueled_index '
            .'ON fleetops_refuels (vehicle_id, refueled_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS fleetops_maintenance_orders_vehicle_scheduled_index '
            .'ON fleetops_maintenance_orders (vehicle_id, scheduled_at)',
        );
        DB::statement(
            'CREATE INDEX IF NOT EXISTS fleetops_expenses_vehicle_incurred_index '
            .'ON fleetops_expenses (vehicle_id, incurred_at)',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS fleetops_fuel_prices_type_effective_index');
        DB::statement('DROP INDEX IF EXISTS fleetops_refuels_vehicle_refueled_index');
        DB::statement('DROP INDEX IF EXISTS fleetops_maintenance_orders_vehicle_scheduled_index');
        DB::statement('DROP INDEX IF EXISTS fleetops_expenses_vehicle_incurred_index');
    }
};
