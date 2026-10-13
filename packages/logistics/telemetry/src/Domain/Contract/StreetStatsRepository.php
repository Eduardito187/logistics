<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\Model\StreetSegmentStats;

/**
 * Puerto de persistencia de la estadística de calles. La implementación vive en Infrastructure.
 *
 * No hay `nextIdentity`: el id del agregado se genera al crearlo por primera vez (la identidad estable es
 * `segmentKey`). `save` es un upsert por `segmentKey`.
 */
interface StreetStatsRepository
{
    public function findByKey(string $segmentKey): ?StreetSegmentStats;

    public function save(StreetSegmentStats $stats): void;

    /**
     * Devuelve las estadísticas de calles, opcionalmente acotadas a un bounding box (el mapa solo pide la
     * zona visible). `null` = todas.
     *
     * @param  ?array{min_lat: float, min_lng: float, max_lat: float, max_lng: float} $bbox
     * @return list<StreetSegmentStats>
     */
    public function allWithin(?array $bbox): array;
}
