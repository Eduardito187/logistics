<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoAreaId;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ZoneId;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Traduce una fila de coverage_zones (leída con ST_AsGeoJSON para la geometría) a la Zone de dominio.
 *
 * Trabaja sobre un array<string, mixed> (la fila casteada desde stdClass): los tipos que devuelve el
 * driver llegan como mixed y se narrowean campo por campo.
 */
final class ZoneMapper
{
    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): Zone
    {
        $id = $row['id'] ?? null;
        $name = $row['name'] ?? null;
        if (!is_string($id) || !is_string($name)) {
            throw CoverageException::because('fila de coverage_zones sin id o name');
        }

        $parentRaw = $row['parent_area_id'] ?? null;
        $parentAreaId = is_string($parentRaw) && $parentRaw !== '' ? GeoAreaId::fromString($parentRaw) : null;

        return Zone::reconstitute(
            ZoneId::fromString($id),
            $name,
            $parentAreaId,
            self::polygonFromGeoJson($row['area_geojson'] ?? null),
            ZoneRules::fromArray(self::decodeRules($row['rules'] ?? null)),
            self::toBool($row['active'] ?? null),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function decodeRules(mixed $rules): array
    {
        // jsonb puede volver como string (driver) o como array (cast Eloquent).
        if (is_array($rules)) {
            /** @var array<string, mixed> $rules */
            return $rules;
        }
        if (is_string($rules) && $rules !== '') {
            $decoded = json_decode($rules, true);
            if (is_array($decoded)) {
                /** @var array<string, mixed> $decoded */
                return $decoded;
            }
        }

        return [];
    }

    private static function polygonFromGeoJson(mixed $geojson): Polygon
    {
        if (!is_string($geojson) || $geojson === '') {
            throw CoverageException::because('fila de coverage_zones sin geometría (area_geojson)');
        }
        $decoded = json_decode($geojson, true);
        if (!is_array($decoded) || !isset($decoded['coordinates']) || !is_array($decoded['coordinates'])) {
            throw CoverageException::because('geometría de coverage_zones mal formada');
        }
        $coordinates = $decoded['coordinates'];
        if (!isset($coordinates[0]) || !is_array($coordinates[0])) {
            throw CoverageException::because('geometría de coverage_zones sin anillo exterior');
        }

        $ring = [];
        foreach ($coordinates[0] as $position) {
            if (!is_array($position) || !isset($position[0], $position[1]) || !is_numeric($position[0]) || !is_numeric($position[1])) {
                throw CoverageException::because('posición inválida en la geometría de coverage_zones');
            }
            $ring[] = [(float) $position[0], (float) $position[1]];
        }

        return Polygon::fromRing($ring);
    }

    private static function toBool(mixed $value): bool
    {
        // pgsql puede devolver bool nativo o 't'/'f' según el driver.
        if (is_bool($value)) {
            return $value;
        }

        return in_array($value, [1, '1', 't', 'true', true], true);
    }
}
