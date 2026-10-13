<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Query\ResolveZoneForPoint;
use Logistics\Coverage\Application\ReadModel\ZoneView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Resolución real punto→zona contra PostGIS (ST_Contains). Necesita la base logistics_test con
 * PostGIS levantada y las migraciones de cobertura aplicadas (RefreshDatabase lo hace en el stack
 * Feature). Se marca skip cuando no hay PostgreSQL disponible para no romper la suite Unit.
 */
it('resolves the zone that contains a point via PostGIS', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    // Cuadrado alrededor de Santa Cruz centro (orden GeoJSON [lng, lat]).
    $ring = [
        [-63.20, -17.80],
        [-63.16, -17.80],
        [-63.16, -17.76],
        [-63.20, -17.76],
        [-63.20, -17.80],
    ];
    $rules = [
        'nodeId' => 'CD-SCZ',
        'services' => ['STD'],
        'baseMinutes' => 120,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];

    $commands->dispatch(new DefineZone('Centro SCZ', null, $ring, $rules));

    $inside = $queries->ask(new ResolveZoneForPoint(-17.78, -63.18));
    expect($inside)->toBeInstanceOf(ZoneView::class);
    /** @var ZoneView $inside */
    expect($inside->name)->toBe('Centro SCZ');

    $outside = $queries->ask(new ResolveZoneForPoint(0.0, 0.0));
    expect($outside)->toBeNull();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);
