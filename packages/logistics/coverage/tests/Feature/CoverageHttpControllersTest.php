<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Application\Command\DefineZone;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Cobertura de los controllers HTTP del contexto coverage (Patrón F del brief W2): ResolveZoneController,
 * ListZonesController, ImportCoverageMapController e ImportCoverageKmlController, con sus FormRequest
 * (ResolveZoneRequest, ImportCoverageMapRequest, ImportCoverageKmlRequest), el envelope ApiResponse y la
 * cadena CQRS detrás (ListZonesHandler, ResolveZoneForPointHandler, ZoneView::toArray, importadores +
 * outbox). Se omite el middleware de puerta para ejercitar solo el contrato de los endpoints.
 *
 * Corre contra logistics_test (RefreshDatabase + PostGIS); se marca skip sin PostgreSQL.
 */
beforeEach(function (): void {
    $this->withoutMiddleware();
})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL + PostGIS (base logistics_test). Correr dentro de los containers.',
);

/**
 * Cuadrado cerrado de ~0.03° centrado en (lng, lat), en orden GeoJSON [lng, lat].
 *
 * @return array<int, array{0: float, 1: float}>
 */
function coverageHttpRing(float $lng, float $lat): array
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

/**
 * Siembra una zona real (vía DefineZone) alrededor de Santa Cruz centro para las consultas HTTP.
 */
function coverageHttpSeedZone(): void
{
    $rules = [
        'nodeId' => 'CD-SCZ',
        'services' => ['STD'],
        'baseMinutes' => 120,
        'productLimits' => [],
        'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
        'serviceDays' => [1, 2, 3, 4, 5],
    ];
    app(CommandBus::class)->dispatch(new DefineZone('Centro SCZ HTTP', null, coverageHttpRing(-63.182, -17.783), $rules));
}

it('lista las zonas de cobertura por la puerta app', function (): void {
    coverageHttpSeedZone();

    $this->getJson('/api/v1/app/coverage/zones')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Centro SCZ HTTP');
});

it('resuelve un punto a su zona por la puerta app', function (): void {
    coverageHttpSeedZone();

    $this->getJson('/api/v1/app/coverage/zones/resolve?lat=-17.783&lng=-63.182')
        ->assertOk()
        ->assertJsonPath('data.name', 'Centro SCZ HTTP');
});

it('devuelve 404 cuando el punto no cae en ninguna zona', function (): void {
    $this->getJson('/api/v1/app/coverage/zones/resolve?lat=0&lng=0')
        ->assertNotFound()
        ->assertJsonPath('error.code', 'zone_not_found');
});

it('rechaza con 422 la resolución sin coordenadas', function (): void {
    $this->getJson('/api/v1/app/coverage/zones/resolve')
        ->assertStatus(422);
});

it('importa un mapa de cobertura GeoJSON por la puerta integrations', function (): void {
    $this->postJson('/api/v1/integrations/coverage/map:import', [
        'type' => 'FeatureCollection',
        'features' => [[
            'type' => 'Feature',
            'properties' => ['name' => 'Centro Import HTTP', 'node' => 'CD-SCZ', 'services' => ['STD'], 'baseMinutes' => 120],
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
    ])->assertStatus(202)
        ->assertJsonPath('data.status', 'import_accepted');

    expect(DB::table('coverage_zones')->where('name', 'Centro Import HTTP')->count())->toBe(1);
});

it('rechaza con 422 una importación GeoJSON sin features', function (): void {
    $this->postJson('/api/v1/integrations/coverage/map:import', [
        'type' => 'FeatureCollection',
    ])->assertStatus(422);
});

it('importa un mapa de cobertura KML (cuerpo XML crudo) por la puerta integrations', function (): void {
    $kml = <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Document>
        <Placemark>
          <name>Norte Import HTTP</name>
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

    $this->call(
        'POST',
        '/api/v1/integrations/coverage/map:import-kml',
        [],
        [],
        [],
        ['CONTENT_TYPE' => 'application/xml'],
        $kml,
    )->assertStatus(202);

    expect(DB::table('coverage_zones')->where('name', 'Norte Import HTTP')->count())->toBe(1);
});
