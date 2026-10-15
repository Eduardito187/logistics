<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Application\Command\ImportCoverage;
use Logistics\Coverage\Application\Report\ImportCoverageReport;
use Logistics\Coverage\Domain\ValueObject\CoverageFormat;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Importación idempotente real contra PostGIS: upsert ON CONFLICT y búsqueda por clave estable
 * (name + parent_area_id con IS NOT DISTINCT FROM) sobre la base logistics_test con PostGIS. Se marca
 * skip cuando no hay PostgreSQL para no romper la suite Unit.
 */

/**
 * @param list<string> $names
 */
function santaCruzGeoJson(array $names): string
{
    $features = [];
    foreach ($names as $i => $name) {
        $base = -63.2 - ($i * 0.1);
        $features[] = [
            'type' => 'Feature',
            'properties' => ['name' => $name, 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
            'geometry' => [
                'type' => 'Polygon',
                'coordinates' => [[
                    [$base, -17.8],
                    [$base + 0.05, -17.8],
                    [$base + 0.05, -17.7],
                    [$base, -17.7],
                    [$base, -17.8],
                ]],
            ],
        ];
    }

    return (string) json_encode(['type' => 'FeatureCollection', 'features' => $features]);
}

it('imports coverage idempotently by department against PostGIS', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $geojson = santaCruzGeoJson(['Centro SCZ', 'Norte SCZ']);

    // Primera importación: crea el departamento y dos zonas.
    $commands->dispatch(new ImportCoverage('Santa Cruz', $geojson, CoverageFormat::GeoJson));

    /** @var ImportCoverageReport $report */
    $report = app(ImportCoverageReport::class);
    expect($report->createdCount())->toBe(2)
        ->and($report->updatedCount())->toBe(0)
        ->and($report->errorCount())->toBe(0);

    $department = DB::table('coverage_geo_areas')
        ->where('name', 'Santa Cruz')
        ->where('level', 'department')
        ->first();
    expect($department)->not->toBeNull();

    $zones = DB::table('coverage_zones')->whereIn('name', ['Centro SCZ', 'Norte SCZ'])->get();
    expect($zones)->toHaveCount(2);
    foreach ($zones as $zone) {
        expect($zone->parent_area_id)->toBe($department->id);
    }

    $idsBefore = DB::table('coverage_zones')
        ->whereIn('name', ['Centro SCZ', 'Norte SCZ'])
        ->orderBy('name')
        ->pluck('id')
        ->all();

    // Segunda importación idéntica: actualiza en el lugar, no duplica ni cambia ids.
    $commands->dispatch(new ImportCoverage('Santa Cruz', $geojson, CoverageFormat::GeoJson));

    /** @var ImportCoverageReport $report2 */
    $report2 = app(ImportCoverageReport::class);
    expect($report2->createdCount())->toBe(0)
        ->and($report2->updatedCount())->toBe(2);

    expect(DB::table('coverage_zones')->whereIn('name', ['Centro SCZ', 'Norte SCZ'])->count())->toBe(2)
        ->and(DB::table('coverage_geo_areas')->where('name', 'Santa Cruz')->where('level', 'department')->count())->toBe(1);

    $idsAfter = DB::table('coverage_zones')
        ->whereIn('name', ['Centro SCZ', 'Norte SCZ'])
        ->orderBy('name')
        ->pluck('id')
        ->all();
    expect($idsAfter)->toBe($idsBefore);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);
