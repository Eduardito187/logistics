<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Contract;

use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Logistics\Telemetry\Domain\ValueObject\MatchedSegment;

/**
 * Puerto de map-matching: mapea una traza de puntos GPS a los segmentos de calle reales que recorrió, con
 * su velocidad. La implementación (OSRM) vive en Infrastructure; el dominio solo ve este contrato.
 *
 * Es I/O externo: se usa en el worker de analítica, nunca en un request de usuario. La implementación debe
 * degradar a `[]` si el servicio no responde, para no reventar la agregación masiva.
 */
interface MapMatcher
{
    /**
     * @param  list<GeoPoint>       $points        traza ordenada por tiempo
     * @param  list<int>            $timestampsSec epoch (segundos) de cada punto, alineado con `$points`
     * @return list<MatchedSegment>
     */
    public function match(array $points, array $timestampsSec): array;
}
