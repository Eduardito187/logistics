<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\SuggestTripSequence;

use DateTimeInterface;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\Contract\TripVehicleReader;
use Logistics\Planning\Application\ReadModel\ShipmentPlanningFacts;
use Logistics\Planning\Application\ReadModel\TripSequenceSuggestionView;
use Logistics\Planning\Application\ReadModel\TripVehicleView;
use Logistics\Planning\Application\Service\CustomerTimeWindow;
use Logistics\Planning\Application\Service\TripBuilderSettings;
use Logistics\Planning\Application\Service\TripRouteCalculator;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve el orden sugerido de un viaje. Lecturas en lote: el camión y su base (1) y los datos de los
 * pedidos (1 conjunto). El recorrido lo calcula TripRouteCalculator: matriz real por carretera si el
 * ruteador responde a tiempo, respaldo local determinista si no.
 *
 * Avisos:
 *  - missing_location: el pedido no tiene ubicación; queda al final del orden, sin ETA.
 *  - window_missed: se llega después del cierre de la hora que eligió el cliente.
 *  - over_capacity: desde ese pedido la carga supera el peso o el volumen del camión.
 *  - out_of_department: el pedido es de otro departamento que la base del camión.
 *
 * @implements QueryHandler<SuggestTripSequence, ?TripSequenceSuggestionView>
 */
final readonly class SuggestTripSequenceHandler implements QueryHandler
{
    public function __construct(
        private TripVehicleReader $vehicles,
        private ShipmentPlanningFactsReader $facts,
        private TripRouteCalculator $routes,
        private TripBuilderSettings $settings,
    ) {}

    public function handle(Query $query): ?TripSequenceSuggestionView
    {
        if (!$query instanceof SuggestTripSequence) {
            throw PlanningException::because('SuggestTripSequenceHandler solo maneja SuggestTripSequence');
        }

        $vehicleId = strtolower(trim($query->vehicleId));
        $vehicle = $this->vehicles->vehiclesFor([$vehicleId])[$vehicleId] ?? null;
        if ($vehicle === null) {
            return null;
        }

        $inputIds = $this->normalize($query->shipmentIds);
        if ($inputIds === []) {
            throw PlanningException::because('El viaje necesita al menos un pedido.');
        }
        if (count($inputIds) > $this->settings->maxStops) {
            throw PlanningException::because("Un viaje admite hasta {$this->settings->maxStops} pedidos.");
        }

        $facts = $this->facts->factsFor($inputIds, withLoad: true);
        $unknown = count($inputIds) - count($facts);
        if ($unknown > 0) {
            throw PlanningException::because(
                $unknown === 1
                    ? 'Uno de los pedidos enviados no existe.'
                    : "{$unknown} de los pedidos enviados no existen.",
            );
        }

        $departAt = $this->settings->departAt($query->date, $query->departAt);
        $timezone = $this->settings->timeZone();

        $located = [];
        $missing = [];
        $windows = [];
        foreach ($inputIds as $id) {
            $point = $facts[$id]->point();
            if ($point === null) {
                $missing[] = $id;

                continue;
            }

            $windows[$id] = CustomerTimeWindow::on($query->date, $facts[$id]->windowStart, $facts[$id]->windowEnd, $timezone);
            $located[] = ['shipment_id' => $id, 'point' => $point, 'window' => $windows[$id]];
        }

        $route = $this->routes->calculate($vehicle->base(), $departAt, $located, reorder: true);

        $stops = [];
        $warnings = [];
        $sequence = 0;
        foreach ($route['order'] as $id) {
            $sequence++;
            $arrival = $departAt->modify('+'.((int) round($route['arrivals'][$id])).' seconds');
            $late = $route['late'][$id];

            $stops[] = [
                'shipment_id' => $id,
                'sequence' => $sequence,
                'eta_at' => $arrival->format(DateTimeInterface::ATOM),
                'within_window' => !$late,
            ];

            $window = $windows[$id] ?? null;
            if ($late && $window !== null) {
                $warnings[] = $this->warning(
                    TripSequenceSuggestionView::WARNING_WINDOW_MISSED,
                    $id,
                    sprintf(
                        'Se llegaría a las %s a %s, fuera de la hora que eligió el cliente (%s).',
                        $arrival->setTimezone($timezone)->format('H:i'),
                        $facts[$id]->label(),
                        $window->label(),
                    ),
                );
            }
        }

        foreach ($missing as $id) {
            $sequence++;
            $stops[] = ['shipment_id' => $id, 'sequence' => $sequence, 'eta_at' => null, 'within_window' => true];
            $warnings[] = $this->warning(
                TripSequenceSuggestionView::WARNING_MISSING_LOCATION,
                $id,
                ucfirst($facts[$id]->label()).' no tiene ubicación en el mapa: no se puede ordenar ni calcular su llegada.',
            );
        }

        $orderedIds = array_map(static fn (array $stop): string => $stop['shipment_id'], $stops);

        return new TripSequenceSuggestionView(
            orderedShipmentIds: $orderedIds,
            stops: $stops,
            distanceKm: round($route['distance_meters'] / 1000.0, 1),
            durationMinutes: (int) round($route['duration_seconds'] / 60.0),
            changed: $orderedIds !== $inputIds,
            warnings: [
                ...$warnings,
                ...$this->capacityWarnings($vehicle, $orderedIds, $facts),
                ...$this->departmentWarnings($vehicle, $orderedIds, $facts),
            ],
        );
    }

    /**
     * Aviso en el PRIMER pedido (en el orden sugerido) con el que la carga acumulada supera el peso del
     * camión, y otro para el volumen. Un camión sin capacidad cargada en Flota no genera aviso.
     *
     * @param  list<string>                                                   $orderedIds
     * @param  array<string, ShipmentPlanningFacts>                           $facts
     * @return list<array{code: string, shipment_id: string, detail: string}>
     */
    private function capacityWarnings(TripVehicleView $vehicle, array $orderedIds, array $facts): array
    {
        $warnings = [];
        $kg = 0.0;
        $m3 = 0.0;
        $kgWarned = $vehicle->capacityKg <= 0.0;
        $m3Warned = $vehicle->capacityM3 <= 0.0;

        foreach ($orderedIds as $id) {
            $kg += $facts[$id]->weightKg;
            $m3 += $facts[$id]->volumeM3;

            if (!$kgWarned && $kg > $vehicle->capacityKg + 1e-6) {
                $kgWarned = true;
                $warnings[] = $this->warning(
                    TripSequenceSuggestionView::WARNING_OVER_CAPACITY,
                    $id,
                    sprintf(
                        'Con %s el viaje supera el peso del camión: la carga total es de %s kg y el camión lleva hasta %s kg.',
                        $facts[$id]->label(),
                        self::number($this->total($facts, 'kg'), 1),
                        self::number($vehicle->capacityKg, 1),
                    ),
                );
            }

            if (!$m3Warned && $m3 > $vehicle->capacityM3 + 1e-6) {
                $m3Warned = true;
                $warnings[] = $this->warning(
                    TripSequenceSuggestionView::WARNING_OVER_CAPACITY,
                    $id,
                    sprintf(
                        'Con %s el viaje supera el volumen del camión: la carga total ocupa %s m³ y el camión lleva hasta %s m³.',
                        $facts[$id]->label(),
                        self::number($this->total($facts, 'm3'), 2),
                        self::number($vehicle->capacityM3, 2),
                    ),
                );
            }
        }

        return $warnings;
    }

    /**
     * Aviso por cada pedido cuyo departamento de destino es conocido y distinto del de la base del camión.
     *
     * @param  list<string>                                                   $orderedIds
     * @param  array<string, ShipmentPlanningFacts>                           $facts
     * @return list<array{code: string, shipment_id: string, detail: string}>
     */
    private function departmentWarnings(TripVehicleView $vehicle, array $orderedIds, array $facts): array
    {
        if ($vehicle->department === null) {
            return [];
        }

        $warnings = [];
        foreach ($orderedIds as $id) {
            $department = $facts[$id]->department;
            if ($department === null || $department === $vehicle->department || $facts[$id]->isTransfer()) {
                continue;
            }

            $warnings[] = $this->warning(
                TripSequenceSuggestionView::WARNING_OUT_OF_DEPARTMENT,
                $id,
                sprintf(
                    '%s se entrega en %s y el camión sale de %s: una ruta no puede cruzar departamentos.',
                    ucfirst($facts[$id]->label()),
                    self::departmentName($department),
                    self::departmentName($vehicle->department),
                ),
            );
        }

        return $warnings;
    }

    /**
     * @param array<string, ShipmentPlanningFacts> $facts
     */
    private function total(array $facts, string $dimension): float
    {
        $total = 0.0;
        foreach ($facts as $fact) {
            $total += $dimension === 'kg' ? $fact->weightKg : $fact->volumeM3;
        }

        return $total;
    }

    /**
     * @return array{code: string, shipment_id: string, detail: string}
     */
    private function warning(string $code, string $shipmentId, string $detail): array
    {
        return ['code' => $code, 'shipment_id' => $shipmentId, 'detail' => $detail];
    }

    /**
     * Ids en minúsculas, sin vacíos ni repetidos, conservando el orden recibido.
     *
     * @param  list<string> $shipmentIds
     * @return list<string>
     */
    private function normalize(array $shipmentIds): array
    {
        $ids = [];
        foreach ($shipmentIds as $id) {
            $id = strtolower(trim($id));
            if ($id !== '') {
                $ids[$id] = true;
            }
        }

        return array_map(strval(...), array_keys($ids));
    }

    /**
     * Nombre legible de un código de departamento: `santa_cruz` → "Santa Cruz".
     */
    private static function departmentName(string $code): string
    {
        return ucwords(str_replace('_', ' ', $code));
    }

    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
