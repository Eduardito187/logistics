<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Salud física de las tablas de telemetría a escala (millones de filas). Aditiva e idempotente:
 * no toca datos ni elimina índices; solo agrega un índice BRIN de apoyo y afina el autovacuum.
 *
 * 1) BRIN en telemetry_pings(recorded_at)
 *    ---------------------------------------------------------------------------------------------
 *    `telemetry_pings` es append-only y se inserta en orden temporal (recorded_at crece de forma
 *    monótona), así que la columna está físicamente correlacionada con el orden en disco: el caso
 *    ideal para BRIN. El BRIN guarda solo el min/max de recorded_at por rango de páginas, por lo que
 *    ocupa kilobytes aunque la tabla llegue a gigas, y resuelve los BARRIDOS por rango de tiempo
 *    (analítica diaria, retención, exportes) saltando los rangos de páginas fuera del intervalo.
 *
 *    TRADE-OFF: ya existe un btree sobre recorded_at (`telemetry_pings_recorded_at_index`) y el
 *    compuesto (vehicle_id, recorded_at). NO se eliminan: el btree sirve las consultas puntuales y el
 *    ORDER BY ... LIMIT con precisión de fila, cosa que el BRIN no hace bien (devuelve rangos de
 *    páginas que luego hay que recheck-ear). El BRIN es COMPLEMENTO para los barridos de rango amplio,
 *    donde un btree sobre millones de filas pesa cientos de MB y el BRIN pesa casi nada. Si en el
 *    futuro se confirma que las consultas puntuales por recorded_at desaparecen, recién ahí tendría
 *    sentido evaluar retirar el btree (decisión de rediseño, fuera de alcance aquí).
 *
 * 2) telemetry_vehicle_positions: NO lleva BRIN (decisión deliberada)
 *    ---------------------------------------------------------------------------------------------
 *    Esta tabla NO es append-only: es la posición EN VIVO, una sola fila por vehículo que se pisa con
 *    cada ping (UPDATE in-place). Su tamaño está acotado por la cantidad de vehículos (cientos/miles),
 *    no crece sin parar, y los UPDATE rompen la correlación físico-temporal de la que depende BRIN.
 *    Por eso BRIN no aporta. Lo que SÍ necesita por su altísima rotación (muchas tuplas muertas sobre
 *    una tabla diminuta) es autovacuum muy agresivo + un fillfactor bajo que favorezca HOT updates.
 *
 * 3) Tuning de autovacuum por tabla (ver valores y justificación abajo).
 *
 * Solo PostgreSQL (motor del proyecto). En otros drivers se omite entero.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // (1) Índice BRIN de apoyo para barridos por rango de tiempo en la tabla append-only de pings.
        DB::statement(
            'CREATE INDEX IF NOT EXISTS telemetry_pings_recorded_at_brin '
            .'ON telemetry_pings USING brin (recorded_at)',
        );

        // (3) Autovacuum agresivo para telemetry_pings (append-heavy, millones de filas).
        //   - vacuum_scale_factor 0.01 + threshold 2000: no esperar al 20% (millones) de tuplas
        //     muertas; mantener el visibility map al día habilita index-only scans (incluido el BRIN).
        //   - vacuum_insert_scale_factor 0.01 (PG13+): disparar vacuum por VOLUMEN DE INSERTS, clave en
        //     una tabla casi sin updates/deletes, para congelar (freeze) temprano y evitar el vacuum de
        //     anti-wraparound masivo que llegaría de golpe sobre toda la tabla.
        //   - analyze_scale_factor 0.005 + threshold 2000: estadísticas frescas para que el planner
        //     estime bien los rangos de recorded_at a medida que la tabla crece.
        //   - vacuum_cost_limit 2000: default 200 es lentísimo para tablas enormes; subirlo deja que el
        //     autovacuum termine en tiempo razonable sin saturar la E/S.
        DB::statement(
            'ALTER TABLE telemetry_pings SET ('
            .'autovacuum_vacuum_scale_factor = 0.01, '
            .'autovacuum_vacuum_threshold = 2000, '
            .'autovacuum_vacuum_insert_scale_factor = 0.01, '
            .'autovacuum_vacuum_insert_threshold = 2000, '
            .'autovacuum_analyze_scale_factor = 0.005, '
            .'autovacuum_analyze_threshold = 2000, '
            .'autovacuum_vacuum_cost_limit = 2000'
            .')',
        );

        // (3) Autovacuum MUY agresivo para la posición en vivo: tabla diminuta pero con UPDATE constante
        //   (una fila por vehículo pisada en cada ping). El 20% por defecto sobre pocas filas nunca se
        //   alcanza mientras el bloat crece; forzamos vacuum por threshold absoluto bajo.
        //   - vacuum_scale_factor 0 + threshold 50: vaciar en cuanto haya ~50 tuplas muertas.
        //   - analyze_scale_factor 0.02: estadísticas frescas pese al tamaño chico.
        //   - fillfactor 70: deja espacio en cada página para que el UPDATE sea HOT (misma página) y no
        //     genere una tupla nueva en otra página; reduce el bloat en origen. Nota: solo aplica a las
        //     filas nuevas/reescritas; no reescribe las existentes.
        DB::statement(
            'ALTER TABLE telemetry_vehicle_positions SET ('
            .'fillfactor = 70, '
            .'autovacuum_vacuum_scale_factor = 0, '
            .'autovacuum_vacuum_threshold = 50, '
            .'autovacuum_analyze_scale_factor = 0.02, '
            .'autovacuum_analyze_threshold = 50, '
            .'autovacuum_vacuum_cost_limit = 1000'
            .')',
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS telemetry_pings_recorded_at_brin');
        DB::statement(
            'ALTER TABLE telemetry_pings RESET ('
            .'autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold, '
            .'autovacuum_vacuum_insert_scale_factor, autovacuum_vacuum_insert_threshold, '
            .'autovacuum_analyze_scale_factor, autovacuum_analyze_threshold, autovacuum_vacuum_cost_limit'
            .')',
        );
        DB::statement(
            'ALTER TABLE telemetry_vehicle_positions RESET ('
            .'fillfactor, autovacuum_vacuum_scale_factor, autovacuum_vacuum_threshold, '
            .'autovacuum_analyze_scale_factor, autovacuum_analyze_threshold, autovacuum_vacuum_cost_limit'
            .')',
        );
    }
};
