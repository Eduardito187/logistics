<?php

declare(strict_types=1);

use Logistics\Coverage\Application\Command\ImportCoverage;
use Logistics\Coverage\Application\Report\ImportCoverageReport;
use Logistics\Coverage\Domain\ValueObject\CoverageFormat;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los caminos de error del ImportCoverageHandler a nivel de aplicación (por el CommandBus,
 * contra PostGIS): documento GeoJSON no parseable, GeoJSON que no es un FeatureCollection, feature
 * malformado dentro de una colección válida, y documento KML malformado. El camino feliz y la
 * idempotencia ya los cubre ImportCoverageTest; aquí se recogen los errores en el ImportCoverageReport.
 *
 * Corre contra logistics_test (RefreshDatabase + PostGIS); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    // Solo requiere la base con PostGIS.
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

it('registra un error cuando el GeoJSON no es parseable', function (): void {
    app(CommandBus::class)->dispatch(new ImportCoverage('La Paz', 'esto no es json', CoverageFormat::GeoJson));

    /** @var ImportCoverageReport $report */
    $report = app(ImportCoverageReport::class);
    expect($report->errorCount())->toBe(1)
        ->and($report->createdCount())->toBe(0);
});

it('registra un error cuando el GeoJSON no es un FeatureCollection', function (): void {
    $notCollection = (string) json_encode(['type' => 'Feature', 'geometry' => []]);

    app(CommandBus::class)->dispatch(new ImportCoverage('La Paz', $notCollection, CoverageFormat::GeoJson));

    /** @var ImportCoverageReport $report */
    $report = app(ImportCoverageReport::class);
    expect($report->errorCount())->toBe(1);
});

it('aísla un feature malformado sin tumbar los válidos', function (): void {
    $mixed = (string) json_encode([
        'type' => 'FeatureCollection',
        'features' => [
            // Feature válido.
            [
                'type' => 'Feature',
                'properties' => ['name' => 'Centro Válido', 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [-63.20, -17.80],
                        [-63.16, -17.80],
                        [-63.16, -17.76],
                        [-63.20, -17.76],
                        [-63.20, -17.80],
                    ]],
                ],
            ],
            // Feature malformado: sin geometría utilizable.
            [
                'type' => 'Feature',
                'properties' => ['name' => 'Roto'],
                'geometry' => ['type' => 'Polygon', 'coordinates' => []],
            ],
        ],
    ]);

    app(CommandBus::class)->dispatch(new ImportCoverage('Santa Cruz', $mixed, CoverageFormat::GeoJson));

    /** @var ImportCoverageReport $report */
    $report = app(ImportCoverageReport::class);
    expect($report->createdCount())->toBe(1)
        ->and($report->errorCount())->toBe(1)
        ->and($report->hasErrors())->toBeTrue();
});

it('registra un error cuando el KML está malformado', function (): void {
    app(CommandBus::class)->dispatch(new ImportCoverage('La Paz', '<kml><Document><broken>', CoverageFormat::Kml));

    /** @var ImportCoverageReport $report */
    $report = app(ImportCoverageReport::class);
    expect($report->errorCount())->toBeGreaterThanOrEqual(1);
});
