<?php

declare(strict_types=1);

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\CoverageFormat;

it('detects KML from XML content', function (): void {
    expect(CoverageFormat::detect('<?xml version="1.0"?><kml></kml>'))->toBe(CoverageFormat::Kml)
        ->and(CoverageFormat::detect("   \n  <kml></kml>"))->toBe(CoverageFormat::Kml);
});

it('detects GeoJSON from JSON content', function (): void {
    expect(CoverageFormat::detect('{"type":"FeatureCollection"}'))->toBe(CoverageFormat::GeoJson)
        ->and(CoverageFormat::detect('  {"type":"FeatureCollection"}'))->toBe(CoverageFormat::GeoJson);
});

it('rejects empty content', function (): void {
    expect(fn () => CoverageFormat::detect("   \n  "))
        ->toThrow(CoverageException::class, 'vacío');
});

it('maps file extensions to a format', function (): void {
    expect(CoverageFormat::fromExtension('kml'))->toBe(CoverageFormat::Kml)
        ->and(CoverageFormat::fromExtension('.KML'))->toBe(CoverageFormat::Kml)
        ->and(CoverageFormat::fromExtension('geojson'))->toBe(CoverageFormat::GeoJson)
        ->and(CoverageFormat::fromExtension('json'))->toBe(CoverageFormat::GeoJson)
        ->and(CoverageFormat::fromExtension('txt'))->toBeNull();
});
