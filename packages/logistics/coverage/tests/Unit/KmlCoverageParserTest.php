<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\Service\KmlCoverageParser;

/**
 * KML mínimo con un Placemark poligonal y reglas en ExtendedData.
 */
function sampleKml(): string
{
    return <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Document>
        <Placemark>
          <name>Centro SCZ</name>
          <ExtendedData>
            <Data name="node"><value>CD-SCZ</value></Data>
            <Data name="services"><value>STD,EXPRESS</value></Data>
            <Data name="baseMinutes"><value>120</value></Data>
            <Data name="serviceDays"><value>1,2,3,4,5</value></Data>
          </ExtendedData>
          <Polygon>
            <outerBoundaryIs>
              <LinearRing>
                <coordinates>
                  -63.20,-17.80,0 -63.16,-17.80,0 -63.16,-17.76,0 -63.20,-17.76,0 -63.20,-17.80,0
                </coordinates>
              </LinearRing>
            </outerBoundaryIs>
          </Polygon>
        </Placemark>
      </Document>
    </kml>
    KML;
}

it('parses a KML placemark into a parsed zone', function (): void {
    $zones = (new KmlCoverageParser)->parse(sampleKml());

    expect($zones)->toHaveCount(1);

    $zone = $zones[0];
    expect($zone->name)->toBe('Centro SCZ');
    // El anillo cerrado tiene 5 posiciones; se descarta la altitud del KML.
    expect($zone->polygon->ring)->toHaveCount(5);
    expect($zone->polygon->ring[0])->toBe([-63.20, -17.80]);

    $rules = $zone->rules->toArray();
    expect($rules['nodeId'])->toBe('CD-SCZ');
    expect($rules['services'])->toBe(['STD', 'EXPRESS']);
    expect($rules['baseMinutes'])->toBe(120);
    expect($rules['serviceDays'])->toBe([1, 2, 3, 4, 5]);
});

it('closes an open ring automatically', function (): void {
    $kml = <<<'KML'
    <?xml version="1.0" encoding="UTF-8"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Placemark>
        <name>Open ring</name>
        <Polygon><outerBoundaryIs><LinearRing><coordinates>
          -63.20,-17.80 -63.16,-17.80 -63.16,-17.76 -63.20,-17.76
        </coordinates></LinearRing></outerBoundaryIs></Polygon>
      </Placemark>
    </kml>
    KML;

    $zones = (new KmlCoverageParser)->parse($kml);
    $ring = $zones[0]->polygon->ring;

    expect($ring[0])->toBe($ring[count($ring) - 1]);
});

it('rejects a KML without placemarks', function (): void {
    $kml = '<?xml version="1.0"?><kml xmlns="http://www.opengis.net/kml/2.2"><Document></Document></kml>';

    expect(fn (): array => (new KmlCoverageParser)->parse($kml))
        ->toThrow(CoverageException::class);
});

it('rejects a placemark without a name', function (): void {
    $kml = <<<'KML'
    <?xml version="1.0"?>
    <kml xmlns="http://www.opengis.net/kml/2.2">
      <Placemark>
        <Polygon><outerBoundaryIs><LinearRing><coordinates>
          -63.20,-17.80 -63.16,-17.80 -63.16,-17.76 -63.20,-17.80
        </coordinates></LinearRing></outerBoundaryIs></Polygon>
      </Placemark>
    </kml>
    KML;

    expect(fn (): array => (new KmlCoverageParser)->parse($kml))
        ->toThrow(CoverageException::class);
});

it('rejects malformed XML', function (): void {
    expect(fn (): array => (new KmlCoverageParser)->parse('<kml><Placemark>'))
        ->toThrow(CoverageException::class);
});
