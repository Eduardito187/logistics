<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Logistics\Coverage\Application\Contract\MunicipalityCatalog;
use Logistics\Coverage\Application\ReadModel\MunicipalityView;
use Logistics\Coverage\Domain\ValueObject\AdminLevel;

/**
 * Implementación Eloquent/SQL del catálogo de municipios.
 *
 * Resuelve en UNA sola consulta, para cada municipio, el departamento ancestro subiendo por parent_id
 * con un CTE recursivo (la cadena normal es Municipio > Provincia > Departamento, pero puede saltarse
 * niveles). Evita N+1 aunque haya miles de municipios. Devuelve read models planos y ordenados por
 * nombre, listos para selects del admin.
 */
final class EloquentMunicipalityCatalog implements MunicipalityCatalog
{
    /**
     * @return array<int, MunicipalityView>
     */
    public function all(): array
    {
        // Para cada área, su departamento ancestro (el de menor profundidad); luego filtramos a los
        // municipios. 'city'/'zone' quedan fuera del listado; solo nos interesan los municipios.
        $sql = <<<'SQL'
            WITH RECURSIVE ancestry AS (
                SELECT id AS start_id, id, name, level, parent_id, 0 AS depth
                FROM coverage_geo_areas
                UNION ALL
                SELECT a.start_id, g.id, g.name, g.level, g.parent_id, a.depth + 1
                FROM coverage_geo_areas g
                JOIN ancestry a ON g.id = a.parent_id
            ),
            department_of AS (
                SELECT DISTINCT ON (start_id) start_id, id AS department_id, name AS department_name
                FROM ancestry
                WHERE level = ?
                ORDER BY start_id, depth ASC
            )
            SELECT
                m.id,
                m.name,
                d.department_id,
                d.department_name
            FROM coverage_geo_areas m
            LEFT JOIN department_of d ON d.start_id = m.id
            WHERE m.level = ?
            ORDER BY m.name ASC
            SQL;

        $rows = $this->connection()->select($sql, [
            AdminLevel::Department->value,
            AdminLevel::Municipality->value,
        ]);

        return array_map(
            function (object $row): MunicipalityView {
                $data = (array) $row;

                return new MunicipalityView(
                    (string) $data['id'],
                    (string) $data['name'],
                    isset($data['department_id']) ? (string) $data['department_id'] : null,
                    isset($data['department_name']) ? (string) $data['department_name'] : null,
                );
            },
            $rows,
        );
    }

    private function connection(): ConnectionInterface
    {
        return (new GeoAreaRecord)->getConnection();
    }
}
