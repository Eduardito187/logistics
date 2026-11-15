<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Service;

use DateTimeImmutable;
use Logistics\Planning\Domain\Service\TripSequenceSuggester;
use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Calcula el recorrido de un viaje de UN camión: el orden (el sugerido, o el recibido tal cual) y su línea
 * de tiempo —llegada a cada parada con la espera de la hora elegida por el cliente y el tiempo de servicio—
 * más la distancia y la duración totales.
 *
 * Lo comparten el orden sugerido del asistente y la creación del plan respetando el orden recibido, para
 * que la ETA que el planificador aceptó sea la misma que queda guardada. Sale de la base del camión; sin
 * base conocida, la primera parada es el punto de salida.
 */
final readonly class TripRouteCalculator
{
    public function __construct(
        private TripMatrixSource $matrices,
        private TripSequenceSuggester $suggester,
        private TripBuilderSettings $settings,
    ) {}

    /**
     * @param  list<array{shipment_id: string, point: GeoPoint, window: CustomerTimeWindow|null}>                                                     $stops   paradas con ubicación, en el orden recibido
     * @param  bool                                                                                                                                   $reorder true = sugerir el mejor orden; false = respetar el recibido
     * @return array{order: list<string>, arrivals: array<string, float>, late: array<string, bool>, distance_meters: float, duration_seconds: float}
     */
    public function calculate(?GeoPoint $base, DateTimeImmutable $departAt, array $stops, bool $reorder): array
    {
        $stops = array_values($stops);
        if ($stops === []) {
            return ['order' => [], 'arrivals' => [], 'late' => [], 'distance_meters' => 0.0, 'duration_seconds' => 0.0];
        }

        // Índice 0 = punto de salida; 1..n = paradas en el orden recibido.
        $points = [$base ?? $stops[0]['point']];
        $windows = [];
        foreach ($stops as $index => $stop) {
            $points[] = $stop['point'];
            if ($stop['window'] !== null) {
                $windows[$index + 1] = $stop['window']->relativeTo($departAt);
            }
        }

        $matrix = $this->matrices->matrixFor($points);
        $serviceSeconds = $this->settings->serviceSeconds();

        $indexes = range(1, count($stops));
        $order = $reorder ? $this->suggester->suggest($matrix, $indexes, $windows, $serviceSeconds) : $indexes;
        $timeline = $this->suggester->timeline($matrix, $order, $windows, $serviceSeconds);

        $orderedIds = [];
        $arrivals = [];
        $late = [];
        foreach ($order as $position => $index) {
            $shipmentId = $stops[$index - 1]['shipment_id'];
            $orderedIds[] = $shipmentId;
            $arrivals[$shipmentId] = $timeline['arrivals'][$position];
            $late[$shipmentId] = $timeline['late'][$position];
        }

        return [
            'order' => $orderedIds,
            'arrivals' => $arrivals,
            'late' => $late,
            'distance_meters' => $timeline['distance_meters'],
            'duration_seconds' => $timeline['duration_seconds'],
        ];
    }
}
