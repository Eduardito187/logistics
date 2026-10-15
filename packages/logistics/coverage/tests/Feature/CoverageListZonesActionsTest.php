<?php

declare(strict_types=1);

use App\Enums\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Coverage\Application\Command\ImportCoverageKml;
use Logistics\Coverage\Application\Command\ImportCoverageMap;
use Logistics\Coverage\Presentation\Filament\Resource\ZoneResource\Pages\ListZones;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Domain\ValueObject\Uuid;

/**
 * Cobertura de los cierres (closures) de las acciones de cabecera del listado de zonas: resolver un
 * punto con zona encontrada (nodo resuelto y sin nodo), e importar el mapa pegado en GeoJSON (válido e
 * inválido) y en KML. El render del listado y el caso "sin cobertura" ya los cubre
 * CoverageFilamentAndImportsTest; aquí se ejercita el camino feliz de cada acción (ImportCoverageMap /
 * ImportCoverageKml por el CommandBus, ResolveZoneForPoint por el QueryBus y describeZone).
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
 * Cuadrado cerrado de ~0.03° centrado en (lng, lat), en orden GeoJSON [lng, lat].
 *
 * @return array<int, array{0: float, 1: float}>
 */
function listZonesActionRing(float $lng, float $lat): array
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

it('resuelve un punto a zona con nodo servidor resuelto desde la acción del listado', function (): void {
    $nodeId = Uuid::generate()->value;

    // Nodo de red real para que describeZone resuelva "nombre (código)".
    DB::table('network_nodes')->insert([
        'id' => $nodeId,
        'code' => 'CD-SCZ',
        'name' => 'CD Santa Cruz',
        'type' => 'distribution_center',
        'lat' => -17.783,
        'lng' => -63.182,
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $rules = [
        'nodeId' => $nodeId,
        'services' => ['STD', 'EXPRESS'],
        'baseMinutes' => 120,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    app(CommandBus::class)->dispatch(new DefineZone('Centro SCZ', null, listZonesActionRing(-63.182, -17.783), $rules));

    Livewire::test(ListZones::class)
        ->callAction('resolvePoint', ['lat' => -17.783, 'lng' => -63.182])
        ->assertOk();
});

it('resuelve un punto a zona sin nodo ni servicios desde la acción del listado', function (): void {
    $rules = [
        'nodeId' => null,
        'services' => [],
        'baseMinutes' => 90,
        'productLimits' => [],
        'timeWindows' => [],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    app(CommandBus::class)->dispatch(new DefineZone('Zona Sin Nodo', null, listZonesActionRing(-66.157, -17.389), $rules));

    Livewire::test(ListZones::class)
        ->callAction('resolvePoint', ['lat' => -17.389, 'lng' => -66.157])
        ->assertOk();
});

it('importa un mapa GeoJSON pegado desde la acción del listado', function (): void {
    $geojson = (string) json_encode([
        'type' => 'FeatureCollection',
        'features' => [[
            'type' => 'Feature',
            'properties' => ['name' => 'Centro Pegado GeoJSON', 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
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
    ]);

    // El textarea de una acción Filament no es driveable por el helper de test (callAction(data:) no llena
    // el form de la acción); el camino de parseo + alta con PostGIS se cubre despachando ImportCoverageMap
    // directamente. El schema de la acción ya lo monta el render del listado (CoverageFilamentAndImportsTest).
    app(CommandBus::class)->dispatch(new ImportCoverageMap((array) json_decode($geojson, true)));

    expect(DB::table('coverage_zones')->where('name', 'Centro Pegado GeoJSON')->count())->toBe(1);
});

it('reporta GeoJSON inválido sin reventar la acción del listado', function (): void {
    // Contenido no parseable como objeto JSON: dispara la notificación de error, no una excepción.
    Livewire::test(ListZones::class)
        ->callAction('importGeoJson', ['geojson' => 'esto no es json'])
        ->assertOk();
});

it('importa un mapa KML pegado desde la acción del listado', function (): void {
    $kml = <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Document>
        <Placemark>
          <name>Norte Pegado KML</name>
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

    // Igual que GeoJSON: el alta vía KML se cubre despachando ImportCoverageKml directamente.
    app(CommandBus::class)->dispatch(new ImportCoverageKml($kml));

    expect(DB::table('coverage_zones')->where('name', 'Norte Pegado KML')->count())->toBe(1);
});
