<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Query\GetVehicleEtas;

use DateTimeImmutable;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Telemetry\Application\Contract\FleetDirectory;
use Logistics\Telemetry\Application\ReadModel\VehicleEtaView;
use Logistics\Telemetry\Application\Service\StopEtaCalculator;
use Logistics\Telemetry\Domain\Contract\StopEtaRepository;
use Logistics\Telemetry\Domain\Contract\VehiclePositionRepository;
use Throwable;

/**
 * Arma la ETA dinámica por parada restante de un vehículo.
 *
 * Por parada se prefiere la ETA GUARDADA por el comando programado (`telemetry:refresh-etas`, que puede usar
 * OSRM) si se calculó desde la posición vigente o una más nueva; si no hay (scheduler apagado, docker nuevo) o
 * quedó vieja, se calcula EN LÍNEA con el respaldo en línea recta. Nunca hace HTTP externo dentro del request.
 *
 * @implements QueryHandler<GetVehicleEtas, VehicleEtaView>
 */
final readonly class GetVehicleEtasHandler implements QueryHandler
{
    public function __construct(
        private StopEtaCalculator $calculator,
        private StopEtaRepository $stored,
        private FleetDirectory $fleet,
        private Clock $clock,
        private VehiclePositionRepository $positions,
    ) {}

    public function handle(Query $query): VehicleEtaView
    {
        assert($query instanceof GetVehicleEtas);

        $result = $this->calculator->calculate($query->vehicleId);
        $positionAt = $result['position']['recorded_at'] ?? null;

        $live = [];
        foreach ($result['etas'] as $eta) {
            $live[$eta['shipment_id']] = $eta;
        }

        $saved = [];
        if (!$result['stale'] && $positionAt !== null) {
            foreach ($this->stored->forVehicle($query->vehicleId) as $row) {
                if ($row['position_recorded_at'] >= $positionAt) {
                    $saved[$row['shipment_id']] = $row;
                }
            }
        }

        $stops = [];
        foreach ($result['stops'] as $stop) {
            $eta = $saved[$stop['shipment_id']] ?? $live[$stop['shipment_id']] ?? null;
            $stops[] = [
                'shipment_id' => $stop['shipment_id'],
                'sequence' => $stop['sequence'],
                'order_reference' => $stop['order_reference'],
                'customer_name' => $stop['customer_name'],
                'lat' => $stop['lat'],
                'lng' => $stop['lng'],
                'planned_eta' => self::iso($stop['planned_eta']),
                'eta' => $eta !== null ? $eta['eta_at']?->format(DATE_ATOM) : null,
                'remaining_distance_m' => $eta['remaining_distance_m'] ?? null,
                'remaining_duration_s' => $eta['remaining_duration_s'] ?? null,
                'method' => $eta['method'] ?? null,
                'computed_at' => $eta !== null ? $eta['computed_at']->format(DATE_ATOM) : null,
            ];
        }

        $position = $result['position'] !== null ? [
            'lat' => $result['position']['lat'],
            'lng' => $result['position']['lng'],
            'recorded_at' => $result['position']['recorded_at']->format(DATE_ATOM),
        ] : null;

        [$offRoute, $offRouteSince] = $this->offRouteState($query->vehicleId, $result['stale']);

        return new VehicleEtaView(
            $query->vehicleId,
            $this->fleet->plates([$query->vehicleId])[$query->vehicleId] ?? null,
            $position,
            $result['stale'],
            $this->clock->now()->format(DATE_ATOM),
            $stops,
            $offRoute,
            $offRouteSince,
        );
    }

    /**
     * Estado "fuera de ruta" del vehículo: el mismo que vigila la ingesta (desvío sostenido más allá de
     * `deviation.max_distance_m` durante `deviation.min_seconds`). Está FUERA de ruta cuando el desvío lleva
     * sostenido al menos ese mínimo (histéresis que evita el parpadeo). Con la posición añeja no se afirma
     * (el camión dejó de reportar: no hay verdad en vivo).
     *
     * @return array{0: bool, 1: string|null}
     */
    private function offRouteState(string $vehicleId, bool $stale): array
    {
        $since = $this->positions->watchState($vehicleId)['off_route_since'];
        if ($since === null || $stale) {
            return [false, $since?->format(DATE_ATOM)];
        }

        $minSeconds = max(0, (int) config('logistics-telemetry.deviation.min_seconds', 120));
        $offRoute = ($this->clock->now()->getTimestamp() - $since->getTimestamp()) >= $minSeconds;

        return [$offRoute, $since->format(DATE_ATOM)];
    }

    /** Normaliza la ETA planificada (texto de la base) a ISO 8601, o null. */
    private static function iso(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
