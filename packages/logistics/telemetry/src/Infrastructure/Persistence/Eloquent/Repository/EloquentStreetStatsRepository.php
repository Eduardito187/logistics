<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Telemetry\Domain\Contract\StreetStatsRepository;
use Logistics\Telemetry\Domain\Model\StreetSegmentStats;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\StreetStatsMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\StreetStatsRecord;

/**
 * Implementación Eloquent del repositorio de estadística de calles. `save` es un upsert por `segment_key`.
 *
 * El filtro por bounding box se hace en PHP sobre la geometría (lista JSON): el repo no usa PostGIS, así que
 * no puede consultar espacialmente en SQL. Para el piloto el volumen de segmentos es acotado; si creciera,
 * se añadiría un índice de bounding box por fila (min/max lat/lng) y el filtro bajaría a SQL.
 */
final readonly class EloquentStreetStatsRepository implements StreetStatsRepository
{
    public function __construct(
        private StreetStatsMapper $mapper = new StreetStatsMapper,
    ) {}

    public function findByKey(string $segmentKey): ?StreetSegmentStats
    {
        $record = StreetStatsRecord::query()->where('segment_key', $segmentKey)->first();

        return $record instanceof StreetStatsRecord ? $this->mapper->toDomain($record) : null;
    }

    public function save(StreetSegmentStats $stats): void
    {
        $row = $this->mapper->toRow($stats);
        StreetStatsRecord::query()->updateOrCreate(['segment_key' => $row['segment_key']], $row);
    }

    public function allWithin(?array $bbox): array
    {
        $all = array_values(StreetStatsRecord::query()
            ->orderBy('segment_key')
            ->get()
            ->map(fn (StreetStatsRecord $record): StreetSegmentStats => $this->mapper->toDomain($record))
            ->all());

        if ($bbox === null) {
            return $all;
        }

        return array_values(array_filter(
            $all,
            static fn (StreetSegmentStats $stats): bool => self::intersectsBbox($stats, $bbox),
        ));
    }

    /**
     * ¿Algún punto de la geometría del segmento cae dentro del bounding box?
     *
     * @param array{min_lat: float, min_lng: float, max_lat: float, max_lng: float} $bbox
     */
    private static function intersectsBbox(StreetSegmentStats $stats, array $bbox): bool
    {
        foreach ($stats->geometry() as $point) {
            if (
                $point['lat'] >= $bbox['min_lat'] && $point['lat'] <= $bbox['max_lat']
                && $point['lng'] >= $bbox['min_lng'] && $point['lng'] <= $bbox['max_lng']
            ) {
                return true;
            }
        }

        return false;
    }
}
