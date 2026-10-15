<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\Factory\GeoAreaRecordFactory;
use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages\CreateGeoArea;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages\EditGeoArea;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages\ListGeoAreas;
use Logistics\Coverage\Presentation\Filament\Resource\GeoAreaResource\Pages\ViewGeoArea;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages\EditZone;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages\ListZones;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages\ViewZone;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de la capa de presentación Filament del contexto coverage (zonas y áreas administrativas) y
 * de los importadores del mapa de cobertura. Montar el listado de zonas construye todas las acciones de
 * cabecera (resolver punto, importar KML/GeoJSON), y los flujos de importación se ejercitan despachando
 * sus comandos por el CommandBus (ImportCoverageMapHandler / ImportCoverageKmlHandler + outbox).
 *
 * Corre contra logistics_test (RefreshDatabase + PostGIS); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    Filament::setCurrentPanel('admin');
    $admin = User::factory()->create();
    $admin->assignRole(Role::Admin);
    $this->actingAs($admin);
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

/**
 * FeatureCollection GeoJSON mínimo (un polígono cuadrado en Santa Cruz) para el importador de mapa.
 *
 * @return array<string, mixed>
 */
function coverageSampleFeatureCollection(): array
{
    return [
        'type' => 'FeatureCollection',
        'features' => [[
            'type' => 'Feature',
            'properties' => ['name' => 'Centro SCZ import', 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
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
        ]],
    ];
}

/**
 * Documento KML mínimo con un Placemark poligonal y reglas en ExtendedData.
 */
function coverageSampleKml(): string
{
    return <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Document>
        <Placemark>
          <name>Norte SCZ import</name>
          <ExtendedData>
            <Data name="node"><value>CD-SCZ</value></Data>
            <Data name="services"><value>STD</value></Data>
            <Data name="baseMinutes"><value>120</value></Data>
            <Data name="serviceDays"><value>1,2,3,4,5</value></Data>
          </ExtendedData>
          <Polygon>
            <outerBoundaryIs>
              <LinearRing>
                <coordinates>
                  -63.30,-17.80,0 -63.26,-17.80,0 -63.26,-17.76,0 -63.30,-17.76,0 -63.30,-17.80,0
                </coordinates>
              </LinearRing>
            </outerBoundaryIs>
          </Polygon>
        </Placemark>
      </Document>
    </kml>
    KML;
}

it('renderiza el listado de zonas (y construye todas sus acciones de cabecera)', function (): void {
    Livewire::test(ListZones::class)->assertOk();
});

it('resuelve un punto a zona desde la acción de cabecera del listado', function (): void {
    Livewire::test(ListZones::class)
        ->callAction('resolvePoint', ['lat' => -17.78, 'lng' => -63.18])
        ->assertOk();
});

it('renderiza el detalle y la edición de una zona', function (): void {
    $zone = ZoneRecord::factory()->create(['name' => 'Centro La Paz']);

    Livewire::test(ViewZone::class, ['record' => $zone->id])->assertOk();
    Livewire::test(EditZone::class, ['record' => $zone->id])->assertOk();
});

it('renderiza el listado, alta, detalle y edición de áreas geográficas', function (): void {
    Livewire::test(ListGeoAreas::class)->assertOk();
    Livewire::test(CreateGeoArea::class)->assertOk();

    $area = GeoAreaRecordFactory::new()->department()->create(['name' => 'La Paz']);

    Livewire::test(ViewGeoArea::class, ['record' => $area->id])->assertOk();
    Livewire::test(EditGeoArea::class, ['record' => $area->id])->assertOk();
});

it('importa un mapa de cobertura GeoJSON por el CommandBus', function (): void {
    app(CommandBus::class)->dispatch(new ImportCoverageMap(coverageSampleFeatureCollection()));

    expect(DB::table('coverage_zones')->where('name', 'Centro SCZ import')->count())->toBe(1);
});

it('importa un mapa de cobertura KML por el CommandBus', function (): void {
    app(CommandBus::class)->dispatch(new ImportCoverageKml(coverageSampleKml()));

    expect(DB::table('coverage_zones')->where('name', 'Norte SCZ import')->count())->toBe(1);
});
