<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Telemetry\Domain\Model\StreetSegmentStats;
use Logistics\Telemetry\Domain\ValueObject\StreetSegmentId;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\StreetStatsRecord;

/**
 * Traduce entre el agregado StreetSegmentStats (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class StreetStatsMapper
{
    public function toDomain(StreetStatsRecord $record): StreetSegmentStats
    {
        $lastSampledAt = $record->last_sampled_at !== null
            ? DateTimeImmutable::createFromInterface($record->last_sampled_at)
            : null;

        return StreetSegmentStats::reconstitute(
            StreetSegmentId::fromString($record->id),
            $record->segment_key,
            $record->name,
            $this->toGeometry($record->geometry),
            $record->sample_count,
            $record->avg_speed_kph,
            $record->min_speed_kph,
            $record->total_seconds,
            $lastSampledAt,
            $this->toHistogram($record->hour_histogram),
        );
    }

    /**
     * @return array{id: string, segment_key: string, name: string|null, geometry: list<array{lat: float, lng: float}>, sample_count: int, avg_speed_kph: float, min_speed_kph: float, total_seconds: int, last_sampled_at: DateTimeImmutable|null, hour_histogram: list<int>}
     */
    public function toRow(StreetSegmentStats $stats): array
    {
        return [
            'id' => $stats->id()->toString(),
            'segment_key' => $stats->segmentKey(),
            'name' => $stats->name(),
            'geometry' => $stats->geometry(),
            'sample_count' => $stats->sampleCount(),
            'avg_speed_kph' => $stats->averageSpeedKph(),
            'min_speed_kph' => $stats->minSpeedKph(),
            'total_seconds' => $stats->totalSeconds(),
            'last_sampled_at' => $stats->lastSampledAt(),
            'hour_histogram' => $stats->hourHistogram(),
        ];
    }

    /**
     * Normaliza la geometría leída del JSON a `list<array{lat: float, lng: float}>`.
     *
     * @return list<array{lat: float, lng: float}>
     */
    private function toGeometry(mixed $geometry): array
    {
        if (!is_array($geometry)) {
            return [];
        }

        $points = [];
        foreach ($geometry as $point) {
            if (is_array($point) && isset($point['lat'], $point['lng']) && is_numeric($point['lat']) && is_numeric($point['lng'])) {
                $points[] = ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']];
            }
        }

        return $points;
    }

    /**
     * Normaliza el histograma leído del JSON a `list<int>`.
     *
     * @return list<int>
     */
    private function toHistogram(mixed $histogram): array
    {
        if (!is_array($histogram)) {
            return [];
        }

        return array_values(array_map(
            static fn (mixed $count): int => is_numeric($count) ? (int) $count : 0,
            $histogram,
        ));
    }
}
