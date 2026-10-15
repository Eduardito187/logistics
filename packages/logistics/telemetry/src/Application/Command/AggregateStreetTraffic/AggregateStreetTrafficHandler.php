<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\AggregateStreetTraffic;

use DateTimeImmutable;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Telemetry\Domain\Contract\MapMatcher;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Contract\StreetStatsRepository;
use Logistics\Telemetry\Domain\Model\StreetSegmentStats;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Domain\ValueObject\StreetSegmentId;

/**
 * Worker de analítica de calles: lee los pings del día (de uno o todos los vehículos), los parte en trazas
 * contiguas, los mapea a segmentos de calle reales con OSRM y acumula cada muestra en StreetSegmentStats.
 *
 * Es I/O pesado en segundo plano, por eso NO corre dentro de un request de usuario. La agregación no emite
 * eventos (ver StreetSegmentStats): es masiva y no produce un hecho de negocio por muestra; por eso el
 * handler no toca el outbox.
 *
 * Depende del contrato `PingRepository` que define el Grupo A (ingesta): acá solo se importa, no se redefine.
 *
 * @implements CommandHandler<AggregateStreetTraffic>
 */
final readonly class AggregateStreetTrafficHandler implements CommandHandler
{
    /** Hueco temporal (segundos) que corta una traza en dos: sobre esto, no es un recorrido continuo. */
    private const TRACE_GAP_SECONDS = 600;

    /** Tope de puntos por traza: OSRM /match acota el número de coordenadas por llamada. */
    private const MAX_TRACE_POINTS = 100;

    public function __construct(
        private PingRepository $pings,
        private MapMatcher $matcher,
        private StreetStatsRepository $streets,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AggregateStreetTraffic);

        $day = new DateTimeImmutable($command->date.' 00:00:00');
        $from = $day;
        $to = $day->modify('+1 day')->modify('-1 second');

        $vehicleIds = $command->vehicleId !== null
            ? [$command->vehicleId]
            : $this->pings->allVehicleIdsWithPingsOn($day);

        foreach ($vehicleIds as $vehicleId) {
            $pings = $this->pings->track($vehicleId, $from, $to);
            foreach ($this->traces($pings) as $trace) {
                $this->aggregateTrace($trace);
            }
        }
    }

    /**
     * Mapea una traza a segmentos de calle y acumula cada uno. Idempotente por `segmentKey`: si el segmento
     * ya existe suma una muestra; si no, lo crea.
     *
     * @param list<VehiclePing> $trace
     */
    private function aggregateTrace(array $trace): void
    {
        if (count($trace) < 2) {
            return;
        }

        $points = [];
        $timestamps = [];
        foreach ($trace as $ping) {
            $points[] = $ping->point();
            $timestamps[] = $ping->recordedAt()->getTimestamp();
        }

        $sampledAt = $trace[array_key_last($trace)]->recordedAt();

        foreach ($this->matcher->match($points, $timestamps) as $segment) {
            $stats = $this->streets->findByKey($segment->segmentKey)
                ?? StreetSegmentStats::start(
                    StreetSegmentId::generate(),
                    $segment->segmentKey,
                    $segment->name,
                    $segment->geometry,
                );

            $stats->addSample($segment->speedKph, $segment->seconds, $segment->hour, $sampledAt);

            $this->streets->save($stats);
        }
    }

    /**
     * Parte los pings de un vehículo en trazas contiguas: corta cuando el hueco temporal supera el umbral o
     * cuando la traza llega al tope de puntos. Descarta trazas de menos de 2 puntos (no hay tramo que medir).
     *
     * @param  list<VehiclePing>       $pings
     * @return list<list<VehiclePing>>
     */
    private function traces(array $pings): array
    {
        usort(
            $pings,
            static fn (VehiclePing $a, VehiclePing $b): int => $a->recordedAt() <=> $b->recordedAt(),
        );

        /** @var list<list<VehiclePing>> $traces */
        $traces = [];
        /** @var list<VehiclePing> $current */
        $current = [];
        $previous = null;

        foreach ($pings as $ping) {
            if ($previous instanceof VehiclePing) {
                $gap = $ping->recordedAt()->getTimestamp() - $previous->recordedAt()->getTimestamp();
                if ($gap > self::TRACE_GAP_SECONDS || count($current) >= self::MAX_TRACE_POINTS) {
                    if (count($current) >= 2) {
                        $traces[] = $current;
                    }
                    $current = [];
                }
            }

            $current[] = $ping;
            $previous = $ping;
        }

        if (count($current) >= 2) {
            $traces[] = $current;
        }

        return $traces;
    }
}
