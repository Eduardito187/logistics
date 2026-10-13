<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetStreetTraffic;

use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Telemetry\Application\ReadModel\StreetTrafficView;
use Logistics\Telemetry\Domain\Contract\StreetStatsRepository;
use Logistics\Telemetry\Domain\Model\StreetSegmentStats;

/**
 * Devuelve el tráfico agregado de calles como proyecciones de lectura para el mapa.
 *
 * Filtra el ruido: descarta los segmentos con menos de `streets.min_samples` muestras (una sola pasada no
 * dice nada sobre el estado habitual de una calle). El umbral vive en config, no en el dominio.
 *
 * @implements QueryHandler<GetStreetTraffic, list<StreetTrafficView>>
 */
final readonly class GetStreetTrafficHandler implements QueryHandler
{
    public function __construct(
        private StreetStatsRepository $streets,
    ) {}

    /**
     * @return list<StreetTrafficView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof GetStreetTraffic);

        $minSamples = (int) config('logistics-telemetry.streets.min_samples', 3);

        $segments = array_values(array_filter(
            $this->streets->allWithin($query->bbox),
            static fn (StreetSegmentStats $stats): bool => $stats->sampleCount() >= $minSamples,
        ));

        return array_map(
            static fn (StreetSegmentStats $stats): StreetTrafficView => StreetTrafficView::fromDomain($stats),
            $segments,
        );
    }
}
