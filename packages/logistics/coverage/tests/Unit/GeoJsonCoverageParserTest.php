<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\Service\GeoJsonCoverageParser;

/**
 * FeatureCollection mínima con una zona.
 *
 * @return array<string, mixed>
 */
function sampleFeatureCollection(): array
{
    return [
        'type' => 'FeatureCollection',
        'features' => [
            [
                'type' => 'Feature',
                'properties' => [
                    'name' => 'Centro',
                    'node' => 'CD-SCZ',
                    'services' => ['STD', 'EXPRESS'],
                    'baseMinutes' => 120,
                    'productLimits' => ['LG-GT32WPP' => 2],
                    'timeWindows' => [['start' => '08:00', 'end' => '18:00']],
                    'serviceDays' => [1, 2, 3, 4, 5],
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [[
                        [-63.2, -17.8],
                        [-63.1, -17.8],
                        [-63.1, -17.7],
                        [-63.2, -17.7],
                        [-63.2, -17.8],
                    ]],
                ],
            ],
        ],
    ];
}

it('parses a minimal FeatureCollection into zones', function (): void {
    $parsed = (new GeoJsonCoverageParser)->parse(sampleFeatureCollection());

    expect($parsed)->toHaveCount(1);

    $zone = $parsed[0];
    expect($zone->name)->toBe('Centro')
        ->and($zone->polygon->ring)->toHaveCount(5)
        ->and($zone->rules->nodeId)->toBe('CD-SCZ')
        ->and($zone->rules->services)->toBe(['STD', 'EXPRESS'])
        ->and($zone->rules->baseMinutes)->toBe(120)
        ->and($zone->rules->productLimits)->toBe(['LG-GT32WPP' => 2])
        ->and($zone->rules->timeWindows)->toHaveCount(1)
        ->and($zone->rules->serviceDays->toArray())->toBe([1, 2, 3, 4, 5]);
});

it('defaults to everyday service when serviceDays is absent', function (): void {
    $collection = sampleFeatureCollection();
    unset($collection['features'][0]['properties']['serviceDays']);

    $parsed = (new GeoJsonCoverageParser)->parse($collection);

    expect($parsed[0]->rules->serviceDays->toArray())->toBe([1, 2, 3, 4, 5, 6, 7]);
});

it('rejects a payload that is not a FeatureCollection', function (): void {
    expect(fn () => (new GeoJsonCoverageParser)->parse(['type' => 'Feature']))
        ->toThrow(CoverageException::class, 'FeatureCollection');
});

it('rejects a feature without a name', function (): void {
    $collection = sampleFeatureCollection();
    unset($collection['features'][0]['properties']['name']);

    expect(fn () => (new GeoJsonCoverageParser)->parse($collection))
        ->toThrow(CoverageException::class, 'properties.name');
});

it('rejects a feature with a non-polygon geometry', function (): void {
    $collection = sampleFeatureCollection();
    $collection['features'][0]['geometry']['type'] = 'Point';

    expect(fn () => (new GeoJsonCoverageParser)->parse($collection))
        ->toThrow(CoverageException::class, 'Polygon');
});
