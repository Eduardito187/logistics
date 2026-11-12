<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use Logistics\Telemetry\Domain\Model\VehiclePing;

/**
 * Reduce una traza GPS para dibujarla (migas de pan por envío) sin mandar miles de puntos. Servicio puro.
 *
 * 1) Espaciado: se conserva un punto solo si está a `minSpacingM` o más del último conservado (descarta el
 *    ruido de un vehículo detenido y los puntos redundantes).
 * 2) Tope: si aún hay más de `maxPoints`, se toma un punto cada `ceil(n / (maxPoints - 1))` (paso uniforme).
 * El primer y el último punto de la traza se conservan siempre (inicio y posición más reciente).
 */
final readonly class TrackDownsampler
{
    /**
     * @param  list<VehiclePing> $track traza ordenada por recorded_at ascendente
     * @return list<VehiclePing>
     */
    public function downsample(array $track, float $minSpacingM, int $maxPoints): array
    {
        $count = count($track);
        if ($count <= 2) {
            return $track;
        }

        $kept = [$track[0]];
        $last = $track[0];
        for ($i = 1; $i < $count - 1; $i++) {
            if ($last->point()->distanceMetersTo($track[$i]->point()) >= $minSpacingM) {
                $kept[] = $track[$i];
                $last = $track[$i];
            }
        }
        $kept[] = $track[$count - 1];

        $maxPoints = max(2, $maxPoints);
        $keptCount = count($kept);
        if ($keptCount <= $maxPoints) {
            return $kept;
        }

        $stride = (int) ceil($keptCount / ($maxPoints - 1));
        $reduced = [];
        for ($i = 0; $i < $keptCount - 1; $i += $stride) {
            $reduced[] = $kept[$i];
        }
        $reduced[] = $kept[$keptCount - 1];

        return $reduced;
    }
}
