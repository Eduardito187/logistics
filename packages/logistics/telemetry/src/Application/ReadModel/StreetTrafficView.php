<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\ReadModel;

use Logistics\Telemetry\Domain\Model\StreetSegmentStats;

/**
 * Proyección de lectura de un segmento de calle para el mapa de tráfico. Primitivos: no expone dominio.
 *
 * Deriva un `congestion_level` legible a partir de la velocidad promedio: calles lentas = congestionadas,
 * calles rápidas = libres. Los umbrales son urbanos y conservadores para el piloto.
 */
final readonly class StreetTrafficView
{
    /** Por debajo de esta velocidad promedio (km/h) la calle se considera congestionada. */
    private const CONGESTED_BELOW_KPH = 10.0;

    /** Por debajo de esta velocidad promedio (km/h) la calle se considera de tráfico moderado. */
    private const MODERATE_BELOW_KPH = 25.0;

    /**
     * @param list<array{lat: float, lng: float}> $geometry
     * @param list<int>                           $hourHistogram
     */
    private function __construct(
        public string $segmentKey,
        public ?string $name,
        public array $geometry,
        public int $sampleCount,
        public float $avgSpeedKph,
        public float $minSpeedKph,
        public string $congestionLevel,
        public array $hourHistogram,
    ) {}

    public static function fromDomain(StreetSegmentStats $stats): self
    {
        return new self(
            $stats->segmentKey(),
            $stats->name(),
            $stats->geometry(),
            $stats->sampleCount(),
            $stats->averageSpeedKph(),
            $stats->minSpeedKph(),
            self::congestionFor($stats->averageSpeedKph()),
            $stats->hourHistogram(),
        );
    }

    /**
     * @return array{segment_key: string, name: string|null, geometry: list<array{lat: float, lng: float}>, sample_count: int, avg_speed_kph: float, min_speed_kph: float, congestion_level: string, hour_histogram: list<int>}
     */
    public function toArray(): array
    {
        return [
            'segment_key' => $this->segmentKey,
            'name' => $this->name,
            'geometry' => $this->geometry,
            'sample_count' => $this->sampleCount,
            'avg_speed_kph' => $this->avgSpeedKph,
            'min_speed_kph' => $this->minSpeedKph,
            'congestion_level' => $this->congestionLevel,
            'hour_histogram' => $this->hourHistogram,
        ];
    }

    /**
     * Clasifica la congestión por velocidad promedio: 'congested' | 'moderate' | 'free'.
     */
    private static function congestionFor(float $avgSpeedKph): string
    {
        if ($avgSpeedKph < self::CONGESTED_BELOW_KPH) {
            return 'congested';
        }
        if ($avgSpeedKph < self::MODERATE_BELOW_KPH) {
            return 'moderate';
        }

        return 'free';
    }
}
