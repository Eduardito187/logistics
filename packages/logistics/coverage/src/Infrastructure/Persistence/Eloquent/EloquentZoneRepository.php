<?php

declare(strict_types=1);

namespace Logistics\Coverage\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionInterface;
use Logistics\Coverage\Domain\Contract\ZoneRepository;
use Logistics\Coverage\Domain\Model\Zone;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Coverage\Domain\ValueObject\ZoneId;

/**
 * Implementación PostGIS del repositorio de zonas.
 *
 * El polígono vive en una columna geometry(Polygon,4326); por eso la geometría se lee y escribe por
 * SQL (ST_GeomFromGeoJSON / ST_AsGeoJSON), no como atributo Eloquent. El resto de columnas son
 * ordinarias. La resolución punto→zona la hace la base con ST_Contains sobre el índice GIST.
 */
final class EloquentZoneRepository implements ZoneRepository
{
    private const string TABLE = ZoneRecord::TABLE;

    private const string SELECT_COLUMNS =
        'id, name, parent_area_id, rules, active, ST_AsGeoJSON(area) AS area_geojson';

    public function nextIdentity(): ZoneId
    {
        return ZoneId::generate();
    }

    public function save(Zone $zone): void
    {
        $sql = 'INSERT INTO '.self::TABLE.' (id, name, parent_area_id, rules, active, area, created_at, updated_at) '
            .'VALUES (?, ?, ?, ?::jsonb, ?, ST_SetSRID(ST_GeomFromGeoJSON(?), 4326), now(), now()) '
            .'ON CONFLICT (id) DO UPDATE SET '
            .'name = EXCLUDED.name, parent_area_id = EXCLUDED.parent_area_id, rules = EXCLUDED.rules, '
            .'active = EXCLUDED.active, area = EXCLUDED.area, updated_at = now()';

        $this->connection()->statement($sql, [
            $zone->id()->toString(),
            $zone->name(),
            $zone->parentAreaId()?->toString(),
            (string) json_encode($zone->rules()->toArray()),
            $zone->isActive(),
            (string) json_encode($zone->polygon()->toGeoJson()),
        ]);
    }

    public function findById(ZoneId $id): ?Zone
    {
        $rows = $this->connection()->select(
            'SELECT '.self::SELECT_COLUMNS.' FROM '.self::TABLE.' WHERE id = ? LIMIT 1',
            [$id->toString()],
        );

        return $rows === [] ? null : ZoneMapper::fromRow((array) $rows[0]);
    }

    /**
     * @return array<int, Zone>
     */
    public function all(): array
    {
        $rows = $this->connection()->select(
            'SELECT '.self::SELECT_COLUMNS.' FROM '.self::TABLE.' ORDER BY name',
        );

        return array_map(static fn (object $row): Zone => ZoneMapper::fromRow((array) $row), $rows);
    }

    public function findZoneContaining(GeoPoint $point): ?Zone
    {
        $rows = $this->connection()->select(
            'SELECT '.self::SELECT_COLUMNS.' FROM '.self::TABLE.' '
            .'WHERE ST_Contains(area::geometry, ST_SetSRID(ST_MakePoint(?, ?), 4326)) LIMIT 1',
            [$point->lng, $point->lat],
        );

        return $rows === [] ? null : ZoneMapper::fromRow((array) $rows[0]);
    }

    private function connection(): ConnectionInterface
    {
        return (new ZoneRecord)->getConnection();
    }
}
