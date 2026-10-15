<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Application\Query\ResolveDepartmentForPoint;
use Logistics\Coverage\Application\ReadModel\LocatedAreaView;
use Logistics\Coverage\Infrastructure\Seeder\CoverageSeeder;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * El seeder de cobertura siembra la jerarquía real de Bolivia (9 departamentos + la cadena completa de
 * La Paz) de forma idempotente contra PostGIS. Se marca skip cuando no hay PostgreSQL.
 */
it('seeds the real Bolivia hierarchy idempotently', function (): void {
    (new CoverageSeeder)->run();

    expect(DB::table('coverage_geo_areas')->where('level', 'department')->count())->toBe(9)
        ->and(DB::table('coverage_geo_areas')->where('level', 'province')->count())->toBeGreaterThanOrEqual(4)
        ->and(DB::table('coverage_geo_areas')->where('level', 'municipality')->count())->toBeGreaterThanOrEqual(5)
        ->and(DB::table('coverage_geo_areas')->where('level', 'city')->count())->toBeGreaterThanOrEqual(4);

    // La cadena completa de La Paz resuelve desde el centro de la ciudad.
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);
    $located = $queries->ask(new ResolveDepartmentForPoint(-16.500, -68.133));
    expect($located)->toBeInstanceOf(LocatedAreaView::class);
    /** @var LocatedAreaView $located */
    expect($located->departmentName)->toBe('La Paz')
        ->and($located->provinceName)->toBe('Pedro Domingo Murillo')
        ->and($located->municipalityName)->toBe('Nuestra Señora de La Paz')
        ->and($located->cityName)->toBe('La Paz');

    // Segunda corrida: idempotente, no duplica departamentos ni zonas.
    $zonesBefore = DB::table('coverage_zones')->count();
    (new CoverageSeeder)->run();

    expect(DB::table('coverage_geo_areas')->where('level', 'department')->count())->toBe(9)
        ->and(DB::table('coverage_zones')->count())->toBe($zonesBefore);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);
