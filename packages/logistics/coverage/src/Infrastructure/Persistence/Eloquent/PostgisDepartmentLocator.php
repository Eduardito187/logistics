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
 * Resuelve todo en la base con una sola consulta: ST_Contains sobre el índice espacial encuentra la
 * zona que contiene el punto, y un CTE recursivo sube por coverage_geo_areas desde el área padre de
 * esa zona (parent_area_id) siguiendo parent_id hasta encontrar el nivel 'department'. De paso captura
 * el nivel 'city' más cercano (el municipio/ciudad del punto).
 *
 * Tolerante: si ningún polígono contiene el punto, si la zona no cuelga de un área, o si no hay un
 * ancestro de nivel 'department', devuelve null (ubicación desconocida).
 */
final class PostgisDepartmentLocator implements DepartmentLocator
{
    public function locate(GeoPoint $point): ?AreaLocation
    {
        // ST_MakePoint toma (lng, lat); el CTE recursivo trepa el árbol administrativo desde el área
        // padre de la zona que contiene el punto hasta el departamento.
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
                (SELECT id   FROM climb WHERE level = 'department' ORDER BY depth ASC LIMIT 1) AS department_id,
                (SELECT name FROM climb WHERE level = 'department' ORDER BY depth ASC LIMIT 1) AS department_name,
                (SELECT id   FROM climb WHERE level = 'city'       ORDER BY depth ASC LIMIT 1) AS city_id,
                (SELECT name FROM climb WHERE level = 'city'       ORDER BY depth ASC LIMIT 1) AS city_name
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
            isset($row['department_name']) ? (string) $row['department_name'] : null,
            isset($row['city_id']) ? (string) $row['city_id'] : null,
            isset($row['city_name']) ? (string) $row['city_name'] : null,
        );
    }

    private function connection(): ConnectionInterface
    {
        return (new ZoneRecord)->getConnection();
    }
}
