<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura del comando de consola logistics:coverage:resolve (Patrón H del brief W2): resuelve punto→zona
 * por el QueryBus y renderiza el ZoneView (camino SUCCESS) o avisa cuando no hay cobertura (camino
 * FAILURE). Ejercita ResolveZoneForPointHandler y ZoneView::toArray desde la consola.
 *
 * Corre contra logistics_test (RefreshDatabase + PostGIS); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    // No requiere autenticación; solo la base con PostGIS.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

/**
 * Cuadrado cerrado de ~0.03° centrado en (lng, lat), en orden GeoJSON [lng, lat].
 *
 * @return array<int, array{0: float, 1: float}>
 */
function resolveConsoleRing(float $lng, float $lat): array
{
    $half = 0.015;

    return [
        [$lng - $half, $lat - $half],
        [$lng + $half, $lat - $half],
        [$lng + $half, $lat + $half],
        [$lng - $half, $lat + $half],
        [$lng - $half, $lat - $half],
    ];
}

it('resuelve un punto a su zona e imprime el detalle (SUCCESS)', function (): void {
    $rules = [
        'nodeId' => 'CD-SCZ',
        'services' => ['STD'],
        'baseMinutes' => 120,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    app(CommandBus::class)->dispatch(new DefineZone('Centro SCZ Consola', null, resolveConsoleRing(-63.182, -17.783), $rules));

    $this->artisan('logistics:coverage:resolve', ['lat' => '-17.783', 'lng' => '-63.182'])
        ->assertSuccessful();
});

it('avisa cuando el punto no cae en ninguna zona (FAILURE)', function (): void {
    $this->artisan('logistics:coverage:resolve', ['lat' => '0', 'lng' => '0'])
        ->assertFailed();
});
