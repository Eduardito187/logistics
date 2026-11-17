<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Defensa en la base de la regla «un chofer = un camión por día, y un camión = una tripulación por día»:
 * dos índices únicos sobre `fleet_crews` (chofer + fecha, vehículo + fecha).
 *
 * Los índices no llevan condición «vigente» porque la tabla guarda SOLO lo vigente: una tripulación que
 * queda sin efecto se mueve a `fleet_crew_history`. Así la unicidad vale también para los lectores de
 * otros contextos, que no filtran nada.
 *
 * No puede fallar por duplicados viejos: en una sola transacción (1) bloquea las escrituras de la tabla,
 * (2) sanea —por cada chofer + fecha y luego por cada vehículo + fecha con más de una tripulación deja la
 * MÁS RECIENTE y mueve las demás al historial— y (3) crea los índices. Con la tabla bloqueada nadie puede
 * meter un duplicado entre el saneo y el índice. No reemite eventos al outbox.
 *
 * Aditiva e idempotente (`IF NOT EXISTS`, `ON CONFLICT DO NOTHING`): correrla de nuevo no cambia nada. El
 * comando `logistics:fleet:reconcile-crews --force` hace el mismo saneo y vuelve a asegurar los índices.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(static function (): void {
            // Permite leer, frena escrituras: dura lo que la transacción (tabla chica, es un instante).
            DB::statement('LOCK TABLE fleet_crews IN SHARE ROW EXCLUSIVE MODE');

            // Mismo chofer y día: queda la más reciente; las otras pasan al historial como «reemplazadas»
            // apuntando a la que quedó (de ahí sale el «camión cambiado» de la actividad).
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           row_number() OVER w AS position,
                           first_value(id) OVER w AS keeper_id,
                           first_value(vehicle_id) OVER w AS keeper_vehicle_id,
                           first_value(created_at) OVER w AS keeper_created_at
                    FROM fleet_crews
                    WINDOW w AS (PARTITION BY driver_id, assembled_for ORDER BY created_at DESC NULLS LAST, id DESC)
                ), retired AS (
                    DELETE FROM fleet_crews c
                    USING ranked r
                    WHERE c.id = r.id AND r.position > 1
                    RETURNING c.id, c.driver_id, c.helper_ids, c.vehicle_id, c.assembled_for, c.shift_starts_at,
                              c.shift_ends_at, c.created_at, r.keeper_id, r.keeper_vehicle_id, r.keeper_created_at
                )
                INSERT INTO fleet_crew_history
                    (id, driver_id, helper_ids, vehicle_id, assembled_for, shift_starts_at, shift_ends_at,
                     crew_created_at, reason, replaced_by_crew_id, new_vehicle_id, source, retired_at)
                SELECT id, driver_id, helper_ids, vehicle_id, assembled_for, shift_starts_at, shift_ends_at,
                       created_at, 'replaced', keeper_id, keeper_vehicle_id, 'backfill',
                       COALESCE(keeper_created_at, LOCALTIMESTAMP)
                FROM retired
                ON CONFLICT (id) DO NOTHING
                SQL);

            // Mismo vehículo y día (otro chofer): queda la más reciente; las otras quedan «desplazadas».
            DB::statement(<<<'SQL'
                WITH ranked AS (
                    SELECT id,
                           row_number() OVER w AS position,
                           first_value(id) OVER w AS keeper_id,
                           first_value(created_at) OVER w AS keeper_created_at
                    FROM fleet_crews
                    WINDOW w AS (PARTITION BY vehicle_id, assembled_for ORDER BY created_at DESC NULLS LAST, id DESC)
                ), retired AS (
                    DELETE FROM fleet_crews c
                    USING ranked r
                    WHERE c.id = r.id AND r.position > 1
                    RETURNING c.id, c.driver_id, c.helper_ids, c.vehicle_id, c.assembled_for, c.shift_starts_at,
                              c.shift_ends_at, c.created_at, r.keeper_id, r.keeper_created_at
                )
                INSERT INTO fleet_crew_history
                    (id, driver_id, helper_ids, vehicle_id, assembled_for, shift_starts_at, shift_ends_at,
                     crew_created_at, reason, replaced_by_crew_id, new_vehicle_id, source, retired_at)
                SELECT id, driver_id, helper_ids, vehicle_id, assembled_for, shift_starts_at, shift_ends_at,
                       created_at, 'displaced', keeper_id, NULL, 'backfill',
                       COALESCE(keeper_created_at, LOCALTIMESTAMP)
                FROM retired
                ON CONFLICT (id) DO NOTHING
                SQL);

            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fleet_crews_driver_day_unique ON fleet_crews (driver_id, assembled_for)');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS fleet_crews_vehicle_day_unique ON fleet_crews (vehicle_id, assembled_for)');
        });
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS fleet_crews_driver_day_unique');
        DB::statement('DROP INDEX IF EXISTS fleet_crews_vehicle_day_unique');
    }
};
