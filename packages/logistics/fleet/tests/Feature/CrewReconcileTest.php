<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saneo de tripulaciones duplicadas de datos viejos (`logistics:fleet:reconcile-crews`): por cada chofer +
 * fecha con más de una vigente deja la MÁS RECIENTE y manda el resto al historial, sin reemitir eventos.
 *
 * Para simular los duplicados que dejó el bug (hoy el índice único los impediría) se bajan los índices
 * únicos dentro de la transacción del test (RefreshDatabase la revierte al terminar) y se insertan filas
 * crudas. Corre contra PostgreSQL (base logistics_test).
 */
const REC_DAY = '2026-11-21';

const REC_CREWS = 'fleet_crews';

const REC_HISTORY = 'fleet_crew_history';

const REC_DRIVER_COL = 'driver_id';

const REC_COMMAND = 'logistics:fleet:reconcile-crews';

beforeEach(function (): void {
    DB::statement('DROP INDEX IF EXISTS fleet_crews_driver_day_unique');
    DB::statement('DROP INDEX IF EXISTS fleet_crews_vehicle_day_unique');
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

/**
 * Inserta una tripulación cruda (sin pasar por el handler, para poder duplicar) con un instante de armado.
 */
function recInsert(string $driverId, string $vehicleId, string $createdAt): string
{
    $id = (string) Str::uuid7();

    DB::table(REC_CREWS)->insert([
        'id' => $id,
        REC_DRIVER_COL => $driverId,
        'helper_ids' => json_encode([]),
        'vehicle_id' => $vehicleId,
        'assembled_for' => REC_DAY,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);

    return $id;
}

it('la simulación (sin --force) no toca las tripulaciones duplicadas', function (): void {
    $driverId = (string) Str::uuid7();
    recInsert($driverId, (string) Str::uuid7(), '2026-11-21 08:00:00');
    recInsert($driverId, (string) Str::uuid7(), '2026-11-21 12:00:00');

    Artisan::call(REC_COMMAND);

    expect(DB::table(REC_CREWS)->where(REC_DRIVER_COL, $driverId)->count())->toBe(2)
        ->and(DB::table(REC_HISTORY)->where(REC_DRIVER_COL, $driverId)->count())->toBe(0);
});

it('con --force deja la más reciente y manda el resto al historial, sin reemitir eventos', function (): void {
    $driverId = (string) Str::uuid7();
    $vehicleOld = (string) Str::uuid7();
    $vehicleNew = (string) Str::uuid7();
    recInsert($driverId, $vehicleOld, '2026-11-21 08:00:00');
    recInsert($driverId, $vehicleNew, '2026-11-21 12:00:00');
    $outboxBefore = DB::table('outbox_messages')->count();

    Artisan::call(REC_COMMAND, ['--force' => true]);

    $vigentes = DB::table(REC_CREWS)->where(REC_DRIVER_COL, $driverId)->get();
    $retired = DB::table(REC_HISTORY)->where(REC_DRIVER_COL, $driverId)->first();

    expect($vigentes)->toHaveCount(1)
        ->and($vigentes->first()?->vehicle_id)->toBe($vehicleNew)
        ->and($retired)->not->toBeNull()
        ->and($retired?->vehicle_id)->toBe($vehicleOld)
        ->and($retired?->reason)->toBe('replaced')
        ->and($retired?->source)->toBe('backfill')
        ->and(DB::table('outbox_messages')->count())->toBe($outboxBefore);
});

it('es idempotente: aplicar de nuevo no cambia nada', function (): void {
    $driverId = (string) Str::uuid7();
    recInsert($driverId, (string) Str::uuid7(), '2026-11-21 08:00:00');
    recInsert($driverId, (string) Str::uuid7(), '2026-11-21 12:00:00');

    Artisan::call(REC_COMMAND, ['--force' => true]);
    Artisan::call(REC_COMMAND, ['--force' => true]);

    expect(DB::table(REC_CREWS)->where(REC_DRIVER_COL, $driverId)->count())->toBe(1)
        ->and(DB::table(REC_HISTORY)->where(REC_DRIVER_COL, $driverId)->count())->toBe(1);
});
