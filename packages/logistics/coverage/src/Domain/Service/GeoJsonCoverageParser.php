<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Service;

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ServiceDays;
use Logistics\Coverage\Domain\ValueObject\TimeWindow;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Servicio de dominio que traduce un FeatureCollection de GeoJSON (el mapa de cobertura actual) a una
 * lista de ParsedZone. Es PHP puro: no sabe de persistencia ni de ids; eso es tarea del caso de uso.
 *
 * Convenciones de cada Feature:
 *  - geometry.type = "Polygon"; se usa el anillo exterior (coordinates[0]), orden GeoJSON [lng, lat].
 *  - properties.name: nombre de la zona (obligatorio).
 *  - properties.node, services, baseMinutes, productLimits, timeWindows, serviceDays: reglas (opcionales).
 */
final class GeoJsonCoverageParser
{
    /**
     * @param  array<string, mixed>   $featureCollection FeatureCollection de GeoJSON ya decodificado
     * @return array<int, ParsedZone>
     */
    public function parse(array $featureCollection): array
    {
        $type = $featureCollection['type'] ?? null;
        if ($type !== 'FeatureCollection') {
            throw CoverageException::because('se esperaba un GeoJSON FeatureCollection');
        }

        $features = $featureCollection['features'] ?? null;
        if (!is_array($features)) {
            throw CoverageException::because('FeatureCollection sin arreglo "features"');
        }

        $zones = [];
        foreach ($features as $feature) {
            if (!is_array($feature)) {
                throw CoverageException::because('cada "feature" debe ser un objeto');
            }
            $zones[] = $this->parseFeature($feature);
        }

        return $zones;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $feature
     */
    private function parseFeature(array $feature): ParsedZone
    {
        $properties = $feature['properties'] ?? [];
        if (!is_array($properties)) {
            throw CoverageException::because('"properties" de un feature debe ser un objeto');
        }

        $name = $properties['name'] ?? null;
        if (!is_string($name) || $name === '') {
            throw CoverageException::because('cada feature necesita properties.name');
        }

        return new ParsedZone(
            $name,
            $this->parsePolygon($feature['geometry'] ?? null),
            $this->parseRules($properties),
        );
    }

    private function parsePolygon(mixed $geometry): Polygon
    {
        if (!is_array($geometry) || ($geometry['type'] ?? null) !== 'Polygon') {
            throw CoverageException::because('geometry debe ser un Polygon');
        }

        $coordinates = $geometry['coordinates'] ?? null;
        if (!is_array($coordinates) || !isset($coordinates[0]) || !is_array($coordinates[0])) {
            throw CoverageException::because('geometry.coordinates mal formado');
        }

        $ring = [];
        foreach ($coordinates[0] as $position) {
            if (!is_array($position) || !isset($position[0], $position[1]) || !is_numeric($position[0]) || !is_numeric($position[1])) {
                throw CoverageException::because('una posición del anillo no es un par [lng, lat] numérico');
            }
            $ring[] = [(float) $position[0], (float) $position[1]];
        }

        return Polygon::fromRing($ring);
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $properties
     */
    private function parseRules(array $properties): ZoneRules
    {
        $node = $properties['node'] ?? null;
        $nodeId = is_string($node) && $node !== '' ? $node : null;

        $services = [];
        $rawServices = $properties['services'] ?? [];
        if (is_array($rawServices)) {
            foreach ($rawServices as $service) {
                if (is_string($service) && $service !== '') {
                    $services[] = $service;
                }
            }
        }

        $baseMinutes = $properties['baseMinutes'] ?? 0;
        $baseMinutes = is_int($baseMinutes) ? $baseMinutes : (is_numeric($baseMinutes) ? (int) $baseMinutes : 0);

        $productLimits = [];
        $rawLimits = $properties['productLimits'] ?? [];
        if (is_array($rawLimits)) {
            foreach ($rawLimits as $sku => $limit) {
                if (is_numeric($limit)) {
                    $productLimits[(string) $sku] = (int) $limit;
                }
            }
        }

        $windows = [];
        $rawWindows = $properties['timeWindows'] ?? [];
        if (is_array($rawWindows)) {
            foreach ($rawWindows as $window) {
                if (is_array($window) && isset($window['start'], $window['end']) && is_string($window['start']) && is_string($window['end'])) {
                    $windows[] = TimeWindow::of($window['start'], $window['end']);
                }
            }
        }

        $days = [];
        $rawDays = $properties['serviceDays'] ?? [];
        if (is_array($rawDays)) {
            foreach ($rawDays as $day) {
                if (is_numeric($day)) {
                    $days[] = (int) $day;
                }
            }
        }
        $serviceDays = $days === [] ? ServiceDays::everyday() : ServiceDays::of(...$days);

        return ZoneRules::define($nodeId, $services, $baseMinutes, $productLimits, $windows, $serviceDays);
    }
}
