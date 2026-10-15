<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Logistics\Coverage\Domain\Contract\MunicipalityLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\MunicipalityId;

/**
 * Implementación PostGIS del localizador de municipio.
 *
 * La geometría disponible en la jerarquía de cobertura vive en las zonas (coverage_zones.area, polígono
 * PostGIS con índice GIST); los niveles administrativos (coverage_geo_areas) no guardan polígono propio.
 * Por eso la extensión real de un municipio es el conjunto de sus zonas: ST_Contains sobre el índice
 * espacial encuentra la zona que contiene el punto, y un CTE recursivo sube por coverage_geo_areas desde
 * el área padre de esa zona (parent_area_id) siguiendo parent_id hasta el nivel 'municipality'. Esto es
 * equivalente a probar ST_Contains/ST_Within contra el polígono del municipio, pero usando la geometría
 * que de hecho existe en el esquema.
 *
 * Tolerante: si ningún polígono contiene el punto, si la zona no cuelga de un área, o si no hay un
 * ancestro de nivel 'municipality' en la cadena, devuelve null (ubicación desconocida).
 */
final class PostgisMunicipalityLocator implements MunicipalityLocator
{
    public function locate(GeoPoint $point): ?MunicipalityId
    {
        // ST_MakePoint toma (lng, lat); el CTE recursivo trepa el árbol administrativo desde el área
        // padre de la zona que contiene el punto hasta encontrar el municipio más cercano (menor depth).
        $sql = <<<'SQL'
            WITH RECURSIVE zone_hit AS (
                SELECT parent_area_id
                FROM coverage_zones
                WHERE parent_area_id IS NOT NULL
                  AND ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326))
                LIMIT 1
            ),
            climb AS (
                SELECT g.id, g.level, g.parent_id, 0 AS depth
                FROM coverage_geo_areas g
                JOIN zone_hit z ON z.parent_area_id = g.id
                UNION ALL
                SELECT g.id, g.level, g.parent_id, c.depth + 1
                FROM coverage_geo_areas g
                JOIN climb c ON g.id = c.parent_id
            )
            SELECT id
            FROM climb
            WHERE level = 'municipality'
            ORDER BY depth ASC
            LIMIT 1
            SQL;

        $rows = $this->connection()->select($sql, [$point->lng, $point->lat]);
        if ($rows === []) {
            return null;
        }

        $municipalityId = ((array) $rows[0])['id'] ?? null;
        if ($municipalityId === null) {
            return null;
        }

        return MunicipalityId::fromString((string) $municipalityId);
    }

    private function connection(): ConnectionInterface
    {
        return (new ZoneRecord)->getConnection();
    }
}
