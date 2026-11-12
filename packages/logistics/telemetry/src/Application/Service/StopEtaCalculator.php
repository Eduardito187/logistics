<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Service;

use DateTimeImmutable;
use DateTimeZone;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\ActiveRouteReader;
use Logistics\Telemetry\Domain\Contract\RoadDurationEstimator;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Logistics\Telemetry\Domain\Service\EtaEstimator;
use Logistics\Telemetry\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Calcula la ETA dinámica de las paradas restantes de un vehículo desde su última posición.
 *
 * Orquesta (Application): posición en vivo + paradas pendientes de la ruta del día + duraciones viales
 * opcionales → EtaEstimator (dominio puro). Con `$road` null (camino de LECTURA dentro de un request) nunca
 * hace I/O externo: usa el respaldo en línea recta. El comando programado pasa el estimador OSRM.
 *
 * Una posición más vieja que `eta.max_position_age_minutes` no produce ETA (el vehículo dejó de reportar).
 *
 * @phpstan-import-type RemainingStop from ActiveRouteReader
 */
final readonly class StopEtaCalculator
{
    public function __construct(
        private VehiclePositionRepository $positions,
        private ActiveRouteReader $routes,
        private Clock $clock,
        private EtaEstimator $estimator = new EtaEstimator,
    ) {}

    /**
     * @return array{position: array{lat: float, lng: float, recorded_at: DateTimeImmutable}|null, stale: bool, stops: list<RemainingStop>, etas: list<array{shipment_id: string, vehicle_id: string, sequence: int, stop_lat: float|null, stop_lng: float|null, eta_at: DateTimeImmutable|null, remaining_distance_m: int|null, remaining_duration_s: int|null, method: string, position_recorded_at: DateTimeImmutable, computed_at: DateTimeImmutable}>}
     */
    public function calculate(string $vehicleId, ?RoadDurationEstimator $road = null): array
    {
        $now = $this->clock->now();
        $stops = $this->routes->remainingStops($vehicleId, $this->localDay($now));
        $live = $this->positions->forVehicle($vehicleId);

        if ($live === null) {
            return ['position' => null, 'stale' => true, 'stops' => $stops, 'etas' => []];
        }

        $position = ['lat' => $live['lat'], 'lng' => $live['lng'], 'recorded_at' => $live['recorded_at']];
        $maxAgeS = max(1, (int) config('logistics-telemetry.eta.max_position_age_minutes', 30)) * 60;
        $stale = ($now->getTimestamp() - $live['recorded_at']->getTimestamp()) > $maxAgeS;

        if ($stale || $stops === []) {
            return ['position' => $position, 'stale' => $stale, 'stops' => $stops, 'etas' => []];
        }

        $origin = GeoPoint::of($live['lat'], $live['lng']);
        $inputs = [];
        $waypoints = [$origin];
        foreach ($stops as $stop) {
            $point = $this->pointOf($stop['lat'], $stop['lng']);
            $inputs[] = ['shipment_id' => $stop['shipment_id'], 'sequence' => $stop['sequence'], 'point' => $point];
            if ($point !== null) {
                $waypoints[] = $point;
            }
        }

        $legs = ($road !== null && count($waypoints) > 1) ? $road->legs($waypoints) : null;

        $estimates = $this->estimator->estimate(
            $origin,
            $now,
            $inputs,
            $legs,
            (float) config('logistics-telemetry.eta.average_speed_kph', 25.0),
            (float) config('logistics-telemetry.eta.detour_factor', 1.3),
            (int) config('logistics-telemetry.eta.service_minutes', 5),
        );

        $etas = [];
        foreach ($estimates as $i => $estimate) {
            $etas[] = [
                'shipment_id' => $estimate['shipment_id'],
                'vehicle_id' => $vehicleId,
                'sequence' => $estimate['sequence'],
                'stop_lat' => $inputs[$i]['point']?->lat,
                'stop_lng' => $inputs[$i]['point']?->lng,
                'eta_at' => $estimate['eta_at'],
                'remaining_distance_m' => $estimate['remaining_distance_m'],
                'remaining_duration_s' => $estimate['remaining_duration_s'],
                'method' => $estimate['method'],
                'position_recorded_at' => $live['recorded_at'],
                'computed_at' => $now,
            ];
        }

        return ['position' => $position, 'stale' => false, 'stops' => $stops, 'etas' => $etas];
    }

    private function pointOf(?float $lat, ?float $lng): ?GeoPoint
    {
        if ($lat === null || $lng === null) {
            return null;
        }

        try {
            return GeoPoint::of($lat, $lng);
        } catch (Throwable) {
            return null;
        }
    }

    /** Día operativo local (zona horaria del horario laboral). */
    private function localDay(DateTimeImmutable $at): DateTimeImmutable
    {
        $timezone = (string) config('logistics-telemetry.working_hours.timezone', 'America/La_Paz');

        return $at->setTimezone(new DateTimeZone($timezone))->setTime(0, 0);
    }
}
