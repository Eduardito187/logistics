<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\AreaLocation;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;

/**
 * Implementación PostGIS del localizador de departamento.
 *
 * Resuelve toda la cadena en la base con una sola consulta: ST_Contains sobre el índice espacial
 * encuentra la zona que contiene el punto, y un CTE recursivo sube por coverage_geo_areas desde el área
 * padre de esa zona (parent_area_id) siguiendo parent_id hasta el nivel 'department'. De paso captura
 * CADA nivel administrativo que atraviesa (ciudad, municipio, provincia), el más cercano al punto de
 * cada uno, para devolver la jerarquía completa resuelta.
 *
 * Tolerante: si ningún polígono contiene el punto, si la zona no cuelga de un área, o si no hay un
 * ancestro de nivel 'department', devuelve null (ubicación desconocida). Los niveles intermedios que no
 * existan en la cadena (p. ej. una zona que cuelga directo del departamento) quedan en null.
 */
final class PostgisDepartmentLocator implements DepartmentLocator
{
    public function locate(GeoPoint $point): ?AreaLocation
    {
        // ST_MakePoint toma (lng, lat); el CTE recursivo trepa el árbol administrativo desde el área
        // padre de la zona que contiene el punto hasta el departamento, recogiendo cada nivel.
        $sql = <<<'SQL'
            WITH RECURSIVE zone_hit AS (
                SELECT parent_area_id
                FROM coverage_zones
                WHERE parent_area_id IS NOT NULL
                  AND ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))
                LIMIT 1
            ),
            climb AS (
                SELECT g.id, g.name, g.level, g.parent_id, 0 AS depth
                FROM coverage_geo_areas g
                JOIN zone_hit z ON z.parent_area_id = g.id
                UNION ALL
                SELECT g.id, g.name, g.level, g.parent_id, c.depth + 1
                FROM coverage_geo_areas g
                JOIN climb c ON g.id = c.parent_id
            )
            SELECT
                (SELECT id   FROM climb WHERE level = 'department'   ORDER BY depth ASC LIMIT 1) AS department_id,
                (SELECT name FROM climb WHERE level = 'department'   ORDER BY depth ASC LIMIT 1) AS department_name,
                (SELECT id   FROM climb WHERE level = 'province'     ORDER BY depth ASC LIMIT 1) AS province_id,
                (SELECT name FROM climb WHERE level = 'province'     ORDER BY depth ASC LIMIT 1) AS province_name,
                (SELECT id   FROM climb WHERE level = 'municipality' ORDER BY depth ASC LIMIT 1) AS municipality_id,
                (SELECT name FROM climb WHERE level = 'municipality' ORDER BY depth ASC LIMIT 1) AS municipality_name,
                (SELECT id   FROM climb WHERE level = 'city'         ORDER BY depth ASC LIMIT 1) AS city_id,
                (SELECT name FROM climb WHERE level = 'city'         ORDER BY depth ASC LIMIT 1) AS city_name
            SQL;

        $rows = $this->connection()->select($sql, [$point->lng, $point->lat]);
        if ($rows === []) {
            return null;
        }

        $row = (array) $rows[0];
        $departmentId = $row['department_id'] ?? null;
        if ($departmentId === null) {
            return null;
        }

        return new AreaLocation(
            (string) $departmentId,
            $this->stringOrNull($row, 'department_name'),
            $this->stringOrNull($row, 'city_id'),
            $this->stringOrNull($row, 'city_name'),
            $this->stringOrNull($row, 'province_id'),
            $this->stringOrNull($row, 'province_name'),
            $this->stringOrNull($row, 'municipality_id'),
            $this->stringOrNull($row, 'municipality_name'),
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private function stringOrNull(array $row, string $key): ?string
    {
        $value = $row[$key] ?? null;

        return $value === null ? null : (string) $value;
    }

    private function connection(): ConnectionInterface
    {
        return (new ZoneRecord)->getConnection();
    }
}
