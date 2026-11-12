<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Service;

use DateInterval;
use DateTimeImmutable;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;

/**
 * Estima la ETA de cada parada restante de una ruta a partir de la posición actual del vehículo. Puro.
 *
 * Encadena las paradas en su orden: origen → parada 1 → parada 2 …, sumando la duración de cada tramo y el
 * tiempo de servicio de cada parada ya alcanzada. La duración de cada tramo sale de:
 *  - `roadLegs` (duraciones viales de OSRM, SIN tráfico en vivo) si se proveen y cuadran con los tramos; o
 *  - el respaldo en línea recta: distancia geodésica × `detourFactor` / `averageSpeedKph`.
 *
 * Una parada sin coordenadas no se puede estimar: sale con ETA null y la cadena sigue desde el último punto
 * conocido. No hay fuente de tráfico: la ETA refleja posición actual + duraciones viales o velocidad media.
 *
 * @phpstan-type StopInput array{shipment_id: string, sequence: int, point: GeoPoint|null}
 * @phpstan-type RoadLeg array{distance_m: float, duration_s: float}
 * @phpstan-type StopEta array{shipment_id: string, sequence: int, eta_at: DateTimeImmutable|null, remaining_distance_m: int|null, remaining_duration_s: int|null, method: string}
 */
final readonly class EtaEstimator
{
    public const string METHOD_OSRM = 'osrm';

    public const string METHOD_STRAIGHT_LINE = 'straight_line';

    /**
     * @param  list<StopInput>    $stops    paradas restantes en orden de visita
     * @param  list<RoadLeg>|null $roadLegs un tramo por cada parada CON coordenadas (origen→1ª, 1ª→2ª…), o null
     * @return list<StopEta>
     */
    public function estimate(
        GeoPoint $origin,
        DateTimeImmutable $departure,
        array $stops,
        ?array $roadLegs,
        float $averageSpeedKph,
        float $detourFactor,
        int $serviceMinutes,
    ): array {
        $located = count(array_filter($stops, static fn (array $s): bool => $s['point'] !== null));
        $legs = ($roadLegs !== null && $located > 0 && count($roadLegs) === $located) ? array_values($roadLegs) : null;
        $method = $legs !== null ? self::METHOD_OSRM : self::METHOD_STRAIGHT_LINE;
        $speedMps = max(1.0, $averageSpeedKph) / 3.6;
        $detour = max(1.0, $detourFactor);
        $serviceS = max(0, $serviceMinutes) * 60;

        $results = [];
        $from = $origin;
        $elapsedS = 0.0;
        $distanceM = 0.0;
        $legIndex = 0;

        foreach ($stops as $stop) {
            $point = $stop['point'];
            if ($point === null) {
                $results[] = [
                    'shipment_id' => $stop['shipment_id'],
                    'sequence' => $stop['sequence'],
                    'eta_at' => null,
                    'remaining_distance_m' => null,
                    'remaining_duration_s' => null,
                    'method' => $method,
                ];

                continue;
            }

            if ($legs !== null) {
                $legDistance = max(0.0, $legs[$legIndex]['distance_m']);
                $legDuration = max(0.0, $legs[$legIndex]['duration_s']);
            } else {
                $legDistance = $from->distanceMetersTo($point) * $detour;
                $legDuration = $legDistance / $speedMps;
            }

            // El servicio de la parada anterior (ya alcanzada en la cadena) demora la salida hacia esta.
            $elapsedS += $legDuration + ($legIndex > 0 ? $serviceS : 0);
            $distanceM += $legDistance;
            $legIndex++;

            $seconds = (int) round($elapsedS);
            $results[] = [
                'shipment_id' => $stop['shipment_id'],
                'sequence' => $stop['sequence'],
                'eta_at' => $departure->add(new DateInterval('PT'.$seconds.'S')),
                'remaining_distance_m' => (int) round($distanceM),
                'remaining_duration_s' => $seconds,
                'method' => $method,
            ];
            $from = $point;
        }

        return $results;
    }
}
