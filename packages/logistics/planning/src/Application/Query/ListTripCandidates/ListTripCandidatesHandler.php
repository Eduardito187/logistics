<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Query\ListTripCandidates;

use DateTimeImmutable;
use Logistics\Planning\Application\Contract\ShipmentPlanningFactsReader;
use Logistics\Planning\Application\Contract\TripCandidateReader;
use Logistics\Planning\Application\Contract\TripVehicleReader;
use Logistics\Planning\Application\ReadModel\ShipmentPlanningFacts;
use Logistics\Planning\Application\ReadModel\TripCandidateCriteria;
use Logistics\Planning\Application\ReadModel\TripCandidatesView;
use Logistics\Planning\Application\ReadModel\TripCandidateView;
use Logistics\Planning\Application\ReadModel\TripVehicleView;
use Logistics\Planning\Application\Service\CustomerTimeWindow;
use Logistics\Planning\Application\Service\TripBuilderSettings;
use Logistics\Planning\Domain\Exception\PlanningException;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Arma la página de candidatos de un viaje. Lecturas, todas en lote: el camión y su base (1), los datos de
 * lo ya elegido (1 conjunto), la página de candidatos ordenada por cercanía en SQL (conteo + página) y los
 * datos legibles de esa página (1 conjunto). Nunca una consulta por candidato ni una llamada al ruteador.
 *
 * Reglas:
 *  - Punto de referencia: el ÚLTIMO pedido elegido con ubicación; si no hay, la base del camión.
 *  - `fits`: el pedido tiene ubicación, su peso y volumen entran en lo que le queda al camión, y su hora
 *    elegida todavía es alcanzable (yendo directo desde la base no se llega después del cierre).
 *  - `suggested`: cabe Y está cerca —a menos del radio configurado— de alguna parada ya elegida o, si no
 *    hay ninguna, de la base del camión.
 *
 * @implements QueryHandler<ListTripCandidates, ?TripCandidatesView>
 */
final readonly class ListTripCandidatesHandler implements QueryHandler
{
    public function __construct(
        private TripVehicleReader $vehicles,
        private ShipmentPlanningFactsReader $facts,
        private TripCandidateReader $candidates,
        private TripBuilderSettings $settings,
        private Clock $clock,
    ) {}

    public function handle(Query $query): ?TripCandidatesView
    {
        if (!$query instanceof ListTripCandidates) {
            throw PlanningException::because('ListTripCandidatesHandler solo maneja ListTripCandidates');
        }

        $vehicleId = strtolower(trim($query->vehicleId));
        $vehicle = $this->vehicles->vehiclesFor([$vehicleId])[$vehicleId] ?? null;
        if ($vehicle === null) {
            return null;
        }

        $timezone = $this->settings->timeZone();
        $now = $this->clock->now()->setTimezone($timezone);
        $isToday = $query->date === $now->format('Y-m-d');

        // Salida: la indicada o la hora por defecto del día; si el viaje es de hoy y esa hora ya pasó, ahora.
        $departAt = $this->settings->departAt($query->date, $query->departAt);
        if ($query->departAt === null && $isToday && $now > $departAt) {
            $departAt = $now;
        }

        $selected = $this->selectedStops($query->selectedShipmentIds);
        $base = $vehicle->base();

        $anchorPoint = $base;
        $anchor = $base === null ? null : ['kind' => 'node', 'name' => $vehicle->baseNodeName ?? 'Base del camión'];
        foreach ($selected['stops'] as $stop) {
            $anchorPoint = $stop['point'];
            $anchor = [
                'kind' => 'shipment',
                'name' => $stop['facts']->orderReference ?? $stop['facts']->customerName ?? 'Pedido',
            ];
        }

        $page = $this->candidates->candidates(new TripCandidateCriteria(
            date: $query->date,
            includeUndated: $isToday,
            department: $vehicle->department,
            anchorLatitude: $anchorPoint?->latitude,
            anchorLongitude: $anchorPoint?->longitude,
            excludedShipmentIds: $selected['ids'],
            search: $query->search !== null && trim($query->search) !== '' ? trim($query->search) : null,
            scope: $query->scope,
            limit: $query->limit ?? max(1, $this->settings->maxCandidates),
            offset: max(0, $query->offset),
        ));

        $pageIds = array_map(static fn (array $row): string => $row['shipment_id'], $page->items);
        $pageFacts = $pageIds === [] ? [] : $this->facts->factsFor($pageIds, withLoad: true);

        $remainingKg = $vehicle->capacityKg > 0.0 ? $vehicle->capacityKg - $selected['kg'] : null;
        $remainingM3 = $vehicle->capacityM3 > 0.0 ? $vehicle->capacityM3 - $selected['m3'] : null;

        $items = [];
        foreach ($page->items as $row) {
            $facts = $pageFacts[$row['shipment_id']] ?? null;
            if ($facts === null) {
                continue;
            }

            $items[] = $this->candidate(
                $facts,
                $row['distance_meters'],
                $query->date,
                $departAt,
                $vehicle,
                $selected['stops'],
                $remainingKg,
                $remainingM3,
            );
        }

        return new TripCandidatesView(
            items: $items,
            total: $page->total,
            anchor: $anchor,
            capacity: [
                'used_units' => $selected['units'],
                'max_units' => null,
                'used_kg' => round($selected['kg'], 1),
                'max_kg' => $vehicle->capacityKg > 0.0 ? $vehicle->capacityKg : null,
                'used_m3' => round($selected['m3'], 3),
                'max_m3' => $vehicle->capacityM3 > 0.0 ? $vehicle->capacityM3 : null,
            ],
        );
    }

    /**
     * Lo ya elegido, en el orden recibido: ids normalizados, las paradas con ubicación (con su posición
     * 1-based en la lista) y la carga que suman.
     *
     * @param  list<string>                                                                                                                                 $shipmentIds
     * @return array{ids: list<string>, stops: list<array{position: int, facts: ShipmentPlanningFacts, point: GeoPoint}>, kg: float, m3: float, units: int}
     */
    private function selectedStops(array $shipmentIds): array
    {
        $ids = [];
        foreach ($shipmentIds as $id) {
            $ids[strtolower(trim($id))] = true;
        }
        $ids = array_map(strval(...), array_keys($ids));

        $facts = $ids === [] ? [] : $this->facts->factsFor($ids, withLoad: true);

        $stops = [];
        $kg = 0.0;
        $m3 = 0.0;
        $units = 0;
        foreach ($ids as $index => $id) {
            $fact = $facts[$id] ?? null;
            if ($fact === null) {
                continue;
            }

            $kg += $fact->weightKg;
            $m3 += $fact->volumeM3;
            $units += $fact->unitsTotal;

            $point = $fact->point();
            if ($point !== null) {
                $stops[] = ['position' => $index + 1, 'facts' => $fact, 'point' => $point];
            }
        }

        return ['ids' => $ids, 'stops' => $stops, 'kg' => $kg, 'm3' => $m3, 'units' => $units];
    }

    /**
     * @param list<array{position: int, facts: ShipmentPlanningFacts, point: GeoPoint}> $selected
     */
    private function candidate(
        ShipmentPlanningFacts $facts,
        ?float $distanceMeters,
        string $date,
        DateTimeImmutable $departAt,
        TripVehicleView $vehicle,
        array $selected,
        ?float $remainingKg,
        ?float $remainingM3,
    ): TripCandidateView {
        $point = $facts->point();
        $window = CustomerTimeWindow::on($date, $facts->windowStart, $facts->windowEnd, $this->settings->timeZone());

        $fitsReason = $this->fitsReason($facts, $point, $window, $departAt, $vehicle->base(), $remainingKg, $remainingM3);
        $fits = $fitsReason === null;

        $suggestedReason = $fits && $point !== null
            ? $this->suggestedReason($point, $distanceMeters, $vehicle, $selected)
            : null;

        return new TripCandidateView(
            shipmentId: $facts->shipmentId,
            orderReference: $facts->orderReference,
            customerName: $facts->customerName,
            shortAddress: $facts->shortAddress,
            deliveryMode: $facts->deliveryMode,
            unitsTotal: $facts->unitsTotal,
            latitude: $point?->latitude,
            longitude: $point?->longitude,
            distanceKmFromAnchor: $point !== null && $distanceMeters !== null ? round($distanceMeters / 1000.0, 2) : null,
            timeWindow: $window?->toArray(),
            weightKg: $facts->weightKg,
            volumeM3: $facts->volumeM3,
            fits: $fits,
            fitsReason: $fitsReason,
            suggested: $suggestedReason !== null,
            suggestedReason: $suggestedReason,
        );
    }

    /**
     * Motivo por el que el pedido NO entra en el viaje, o null si entra.
     */
    private function fitsReason(
        ShipmentPlanningFacts $facts,
        ?GeoPoint $point,
        ?CustomerTimeWindow $window,
        DateTimeImmutable $departAt,
        ?GeoPoint $base,
        ?float $remainingKg,
        ?float $remainingM3,
    ): ?string {
        if ($point === null) {
            return 'El pedido no tiene ubicación en el mapa.';
        }

        if ($remainingKg !== null && $facts->weightKg > $remainingKg + 1e-6) {
            return sprintf(
                'Supera el peso disponible del camión: pesa %s kg y quedan %s kg.',
                self::number($facts->weightKg, 1),
                self::number(max(0.0, $remainingKg), 1),
            );
        }

        if ($remainingM3 !== null && $facts->volumeM3 > $remainingM3 + 1e-6) {
            return sprintf(
                'Supera el volumen disponible del camión: ocupa %s m³ y quedan %s m³.',
                self::number($facts->volumeM3, 2),
                self::number(max(0.0, $remainingM3), 2),
            );
        }

        if ($window !== null) {
            // Llegada más temprana posible: directo desde la base (línea recta con rodeo y velocidad promedio).
            $earliest = $departAt;
            if ($base !== null) {
                $metersPerSecond = max(1.0, $this->settings->averageSpeedKmh) * 1000.0 / 3600.0;
                $seconds = (int) round($base->haversineMeters($point) * max(1.0, $this->settings->detourFactor) / $metersPerSecond);
                $earliest = $departAt->modify("+{$seconds} seconds");
            }

            if ($earliest > $window->to) {
                return sprintf(
                    'Fuera de la ventana horaria: el cliente pidió %s y el camión llegaría cerca de las %s.',
                    $window->label(),
                    $earliest->setTimezone($this->settings->timeZone())->format('H:i'),
                );
            }
        }

        return null;
    }

    /**
     * Motivo por el que se recomienda sumar el pedido ahora, o null si no se recomienda.
     *
     * @param list<array{position: int, facts: ShipmentPlanningFacts, point: GeoPoint}> $selected
     */
    private function suggestedReason(GeoPoint $point, ?float $distanceFromAnchor, TripVehicleView $vehicle, array $selected): ?string
    {
        $radius = $this->settings->suggestRadiusMeters;

        if ($selected === []) {
            if ($distanceFromAnchor === null || $distanceFromAnchor > $radius) {
                return null;
            }

            return sprintf('A %s de %s.', self::distance($distanceFromAnchor), $vehicle->baseNodeName ?? 'la base del camión');
        }

        $nearestPosition = null;
        $nearestMeters = INF;
        foreach ($selected as $stop) {
            $meters = $point->haversineMeters($stop['point']);
            if ($meters < $nearestMeters) {
                $nearestMeters = $meters;
                $nearestPosition = $stop['position'];
            }
        }

        if ($nearestPosition === null || $nearestMeters > $radius) {
            return null;
        }

        if ($nearestMeters <= $this->settings->sameBlockMeters) {
            return "En la misma cuadra que la parada {$nearestPosition}.";
        }

        return sprintf('A %s de la parada %d.', self::distance($nearestMeters), $nearestPosition);
    }

    /**
     * Distancia legible: metros redondeados a la decena por debajo de 1 km ("400 m"), si no kilómetros con
     * un decimal y coma ("1,2 km").
     */
    private static function distance(float $meters): string
    {
        if ($meters < 1000.0) {
            return ((int) (round($meters / 10.0) * 10)).' m';
        }

        return self::number($meters / 1000.0, 1).' km';
    }

    private static function number(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', '.');
    }
}
