<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Service;

use Logistics\Planning\Domain\ValueObject\AutoPlanComposition;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\PlanStrategy;
use Logistics\Planning\Domain\ValueObject\TruckLoad;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Motor de EMPAQUE del auto-planificador: a partir de los pedidos pendientes y los camiones disponibles,
 * arma qué pedidos van en qué camión. PHP puro, determinista y sin I/O: no rutea (eso lo hace el motor
 * de rutas después) ni toca base de datos.
 *
 * Invariante inamovible: un camión entrega a UN SOLO departamento. Se garantiza particionando los
 * pedidos por departamento antes de empacar y ligando cada camión a un departamento en cuanto recibe su
 * primer pedido (ver PackingBin).
 *
 * Dos modos:
 * - Optimization: parte por departamento y, dentro de cada uno, empaca first-fit-decreasing por volumen
 *   agrupando por zona (pedidos de la misma zona tienden a caer en el mismo camión → menos distancia).
 * - Slots: parte por (departamento, franja): respeta la franja elegida por el cliente, un camión sirve
 *   una sola franja. Dentro de cada grupo, el mismo empaque por cercanía.
 *
 * Determinismo: re-ejecutar con las mismas entradas da el mismo resultado (camiones y grupos ordenados
 * por claves estables), condición para que la corrida idempotente no produzca planes distintos.
 */
final readonly class DailyPlanComposer
{
    public function __construct(
        private VehicleCompatibility $compatibility = new VehicleCompatibility(),
    ) {}

    /**
     * @param list<DeliveryCandidate>   $candidates
     * @param list<VehicleCapacitySpec> $vehicles
     */
    public function compose(array $candidates, array $vehicles, AutoPlanParameters $params): AutoPlanComposition
    {
        $bins = $this->buildBins($vehicles, $params);
        $groups = $this->groupCandidates($candidates, $params->strategy);

        /** @var list<DeliveryCandidate> $unassigned */
        $unassigned = [];

        // Los grupos se procesan en orden estable de clave. Los bins son un pool compartido: un camión
        // usado por un grupo (p. ej. una franja) ya no acepta otro departamento/franja, por el ligado.
        foreach ($this->sortedGroupKeys($groups) as $groupKey) {
            $slotKey = $this->slotKeyForGroup($groupKey, $params->strategy);

            foreach ($this->sortForPacking($groups[$groupKey]) as $candidate) {
                if (!$this->placeInFirstFit($bins, $candidate, $slotKey)) {
                    $unassigned[] = $candidate;
                }
            }
        }

        return new AutoPlanComposition($this->trucksFrom($bins), $unassigned);
    }

    /**
     * Construye los bins desde los camiones con TRIPULACIÓN completa (los demás no pueden salir a ruta),
     * con la capacidad UTILIZABLE (nominal menos la holgura configurada). Orden estable: primero los de
     * mayor volumen (llenan mejor), desempatando por kg y por id.
     *
     * @param  list<VehicleCapacitySpec> $vehicles
     * @return list<PackingBin>
     */
    private function buildBins(array $vehicles, AutoPlanParameters $params): array
    {
        $usable = array_values(array_filter($vehicles, static fn (VehicleCapacitySpec $v): bool => $v->hasCrew));

        usort($usable, static function (VehicleCapacitySpec $a, VehicleCapacitySpec $b): int {
            return [$b->capacityM3, $b->capacityKg, $a->vehicleId]
                <=> [$a->capacityM3, $a->capacityKg, $b->vehicleId];
        });

        $ratio = $params->usableCapacityRatio();

        return array_map(
            fn (VehicleCapacitySpec $v): PackingBin => new PackingBin(
                $v->vehicleId,
                $v->departmentId,
                $v->capacityKg * $ratio,
                $v->capacityM3 * $ratio,
                $v->profile,
                $this->compatibility,
            ),
            $usable,
        );
    }

    /**
     * Agrupa los candidatos según el modo: por departamento (optimization) o por departamento+franja
     * (slots). La clave del grupo es estable y legible.
     *
     * @param  list<DeliveryCandidate>                $candidates
     * @return array<string, list<DeliveryCandidate>>
     */
    private function groupCandidates(array $candidates, PlanStrategy $strategy): array
    {
        /** @var array<string, list<DeliveryCandidate>> $groups */
        $groups = [];

        foreach ($candidates as $candidate) {
            $key = $strategy === PlanStrategy::Slots
                ? $candidate->departmentKey().'|'.$candidate->slotKey()
                : $candidate->departmentKey();

            $groups[$key][] = $candidate;
        }

        return $groups;
    }

    /**
     * Claves de grupo en orden estable, para que el reparto del pool de camiones sea determinista.
     *
     * @param  array<string, list<DeliveryCandidate>> $groups
     * @return list<string>
     */
    private function sortedGroupKeys(array $groups): array
    {
        $keys = array_keys($groups);
        sort($keys);

        return $keys;
    }

    /**
     * En modo slots, extrae la franja de la clave del grupo (para ligar el camión a esa franja). En modo
     * optimization no hay restricción de franja (null).
     */
    private function slotKeyForGroup(string $groupKey, PlanStrategy $strategy): ?string
    {
        if ($strategy !== PlanStrategy::Slots) {
            return null;
        }

        $parts = explode('|', $groupKey, 2);

        return $parts[1] ?? null;
    }

    /**
     * Orden de empaque dentro de un grupo: primero por zona (mantener juntos los pedidos cercanos) y,
     * dentro de la zona, first-fit-decreasing por volumen (desempatando por peso y por id). Determinista.
     *
     * @param  list<DeliveryCandidate> $candidates
     * @return list<DeliveryCandidate>
     */
    private function sortForPacking(array $candidates): array
    {
        $sorted = $candidates;

        usort($sorted, static function (DeliveryCandidate $a, DeliveryCandidate $b): int {
            if ($a->zoneKey !== $b->zoneKey) {
                return $a->zoneKey <=> $b->zoneKey;
            }

            return [$b->volumeM3, $b->weightKg, $a->shipmentId]
                <=> [$a->volumeM3, $a->weightKg, $b->shipmentId];
        });

        return $sorted;
    }

    /**
     * Coloca el candidato en el PRIMER bin que lo acepte (first-fit). Devuelve false si ninguno puede.
     *
     * @param list<PackingBin> $bins
     */
    private function placeInFirstFit(array $bins, DeliveryCandidate $candidate, ?string $slotKey): bool
    {
        foreach ($bins as $bin) {
            if ($bin->accepts($candidate, $slotKey)) {
                $bin->place($candidate, $slotKey);

                return true;
            }
        }

        return false;
    }

    /**
     * Convierte los bins con al menos un pedido en la carga de cada camión.
     *
     * @param  list<PackingBin> $bins
     * @return list<TruckLoad>
     */
    private function trucksFrom(array $bins): array
    {
        $trucks = [];
        foreach ($bins as $bin) {
            if ($bin->isEmpty()) {
                continue;
            }
            $trucks[] = new TruckLoad(
                $bin->vehicleId(),
                $bin->departmentId(),
                $bin->candidates(),
                $bin->usedKg(),
                $bin->usedM3(),
            );
        }

        return $trucks;
    }
}
