<?php

declare(strict_types=1);

use App\Support\Cockpit\CockpitPulse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

// Variación (delta ▲/▼) de los KPIs de la Torre de Control contra la última foto histórica anterior
// (cockpit_metric_snapshots). La flecha muestra la dirección real del cambio; el tono (bueno/malo) depende
// de la métrica (más entregados = bueno; más demorados = malo). Sin línea base, el delta va null.

beforeEach(function (): void {
    if (env('DB_CONNECTION') !== 'pgsql') {
        return;
    }
    DB::table('cockpit_metric_snapshots')->delete();
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

function metricByKey(string $key): array
{
    foreach (app(CockpitPulse::class)->metrics() as $m) {
        if ($m['key'] === $key) {
            return $m;
        }
    }

    return [];
}

it('calcula el delta contra la foto anterior, con tono por métrica', function (): void {
    $yesterday = now()->subDay()->toDateString();
    // Tablas operativas vacías → valores de hoy = 0. Se fija una base anterior distinta por métrica.
    DB::table('cockpit_metric_snapshots')->insert([
        ['metric_key' => 'delivered_today', 'captured_on' => $yesterday, 'value' => 4, 'created_at' => now(), 'updated_at' => now()],
        ['metric_key' => 'delayed', 'captured_on' => $yesterday, 'value' => 5, 'created_at' => now(), 'updated_at' => now()],
        ['metric_key' => 'active_shipments', 'captured_on' => $yesterday, 'value' => 10, 'created_at' => now(), 'updated_at' => now()],
    ]);

    // Entregados hoy: 0 vs 4 → bajó (▼) y eso es MALO (más es mejor).
    $delivered = metricByKey('delivered_today');
    expect($delivered['delta'])->toBe('−4')
        ->and($delivered['trend'])->toBe('down')
        ->and($delivered['delta_tone'])->toBe('negative');

    // Demorados: 0 vs 5 → bajó (▼) y eso es BUENO (menos es mejor).
    $delayed = metricByKey('delayed');
    expect($delayed['delta'])->toBe('−5')
        ->and($delayed['trend'])->toBe('down')
        ->and($delayed['delta_tone'])->toBe('positive');

    // Envíos activos: conteo neutro (ni bueno ni malo) → delta sí, pero tono neutro.
    $active = metricByKey('active_shipments');
    expect($active['delta'])->toBe('−10')
        ->and($active['trend'])->toBe('down')
        ->and($active['delta_tone'])->toBe('neutral');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('sin foto anterior el delta va null (no inventa variación)', function (): void {
    $delivered = metricByKey('delivered_today');

    expect($delivered['delta'])->toBeNull()
        ->and($delivered['trend'])->toBe('flat')
        ->and($delivered['delta_tone'])->toBe('neutral');
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');

it('el comando guarda una foto por métrica y es idempotente en el día', function (): void {
    Artisan::call('cockpit:metrics:snapshot');
    $today = now()->toDateString();
    expect(DB::table('cockpit_metric_snapshots')->where('captured_on', $today)->count())->toBe(6);

    // Re-ejecutar el mismo día no duplica (updateOrInsert por métrica+fecha).
    Artisan::call('cockpit:metrics:snapshot');
    expect(DB::table('cockpit_metric_snapshots')->where('captured_on', $today)->count())->toBe(6);
})->skip(env('DB_CONNECTION') !== 'pgsql', 'Requiere PostgreSQL (base logistics_test).');
