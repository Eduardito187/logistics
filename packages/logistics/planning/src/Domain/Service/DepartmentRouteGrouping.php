<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Reglas PURAS de particionado del auto-planificador, compartidas por los backends de ruteo (builtin,
 * vroom): cómo se agrupan los pedidos por departamento (y franja en modo slots), qué camiones son
 * elegibles para un grupo y qué punto usar de depósito. PHP puro, determinista, sin I/O.
 *
 * Centraliza la invariante inamovible "un camión = un departamento": al procesar un grupo con las paradas
 * de un solo departamento y solo sus camiones elegibles, cualquier ruta que salga es de un único departamento.
 */
final readonly class DepartmentRouteGrouping
{
    /**
     * Agrupa los candidatos por departamento (optimization) o departamento+franja (slots), con las claves
     * en orden estable para que el reparto del pool sea determinista.
     *
     * @param  list<DeliveryCandidate>                $candidates
     * @return array<string, list<DeliveryCandidate>>
     */
    public function groupCandidates(array $candidates, PlanStrategy $strategy): array
    {
        /** @var array<string, list<DeliveryCandidate>> $groups */
        $groups = [];
        foreach ($candidates as $candidate) {
            $key = $strategy === PlanStrategy::Slots
                ? $candidate->departmentKey().'|'.$candidate->slotKey()
                : $candidate->departmentKey();
            $groups[$key][] = $candidate;
        }

        ksort($groups);

        return $groups;
    }

    /**
     * Camiones del pool elegibles para un departamento: los de ese departamento más los flexibles (sin
     * departamento propio). Un pedido sin departamento resuelto solo lo toman los flexibles.
     *
     * @param  list<VehicleCapacitySpec> $pool
     * @return list<VehicleCapacitySpec>
     */
    public function eligibleVehicles(array $pool, ?string $departmentId): array
    {
        return array_values(array_filter(
            $pool,
            static fn (VehicleCapacitySpec $v): bool => $v->departmentId === $departmentId || $v->departmentId === null,
        ));
    }

    /**
     * Depósito del grupo: el origen (CD) si se conoce; si no, el nodo base del camión elegible más grande;
     * y como último recurso, el medoide de las paradas (la parada más céntrica del grupo).
     *
     * @param list<VehicleCapacitySpec> $eligible
     * @param list<DeliveryCandidate>   $group
     */
    public function resolveDepot(?GeoPoint $origin, array $eligible, array $group): GeoPoint
    {
        if ($origin !== null) {
            return $origin;
        }

        $byCapacity = $eligible;
        usort($byCapacity, static fn (VehicleCapacitySpec $a, VehicleCapacitySpec $b): int => [$b->capacityKg, $a->vehicleId] <=> [$a->capacityKg, $b->vehicleId]);
        foreach ($byCapacity as $vehicle) {
            $point = $vehicle->homeBasePoint();
            if ($point !== null) {
                return $point;
            }
        }

        return $this->medoid($group);
    }

    /**
     * Puntos de la matriz del grupo: índice 0 el depósito, 1..n las paradas en el orden del grupo.
     *
     * @param  list<DeliveryCandidate> $group
     * @return list<GeoPoint>
     */
    public function pointsFor(GeoPoint $depot, array $group): array
    {
        $points = [$depot];
        foreach ($group as $candidate) {
            $points[] = GeoPoint::of($candidate->latitude, $candidate->longitude);
        }

        return $points;
    }

    /**
     * Parada más céntrica del grupo (minimiza la suma de distancias al resto): depósito aproximado cuando
     * no hay origen ni nodo base. Determinista.
     *
     * @param list<DeliveryCandidate> $group
     */
    public function medoid(array $group): GeoPoint
    {
        $points = array_map(static fn (DeliveryCandidate $c): GeoPoint => GeoPoint::of($c->latitude, $c->longitude), $group);

        $bestIndex = 0;
        $bestSum = INF;
        foreach ($points as $i => $from) {
            $sum = 0.0;
            foreach ($points as $to) {
                $sum += $from->haversineMeters($to);
            }
            if ($sum < $bestSum) {
                $bestSum = $sum;
                $bestIndex = $i;
            }
        }

        return $points[$bestIndex];
    }
}
