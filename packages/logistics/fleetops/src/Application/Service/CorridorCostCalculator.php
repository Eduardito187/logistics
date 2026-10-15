<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\Service;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\DepartureRepository;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\Service\CostAllocator;
use Logistics\FleetOps\Domain\ValueObject\Money;

/**
 * Agrega el costo operativo de la flota propia por CORREDOR (ROADMAP 3-01), reutilizado por la lectura de
 * costos por corredor y por la de rentabilidad (3-06) para no duplicar la atribución.
 *
 * Supuesto de atribución (documentado): fleetops no captura distancia ni ingreso por entrega individual.
 * El corredor de una salida se toma de `route_ref` y, si falta, de `destination` (si ambos faltan,
 * '(unassigned)'). El costo de cada salida se atribuye como km recorridos × costo por km del vehículo en
 * el período (combustible + mantenimiento + gastos / km de telemetría). Las "entregas" de una salida son
 * sus ítems de carga; si no hay ítems declarados, se cuenta 1 entrega por salida.
 */
final readonly class CorridorCostCalculator
{
    /** Clave del corredor cuando la salida no declara ni ruta ni destino. */
    public const UNASSIGNED = '(unassigned)';

    public function __construct(
        private DepartureRepository $departures,
        private FleetCostCalculator $vehicleCosts,
        private TelemetryDistanceReader $telemetry,
        private CostAllocator $allocator = new CostAllocator,
    ) {}

    /**
     * Costo por corredor en el rango. Devuelve una lista ordenada por costo descendente.
     *
     * @return list<array{corridor: string, departures: int, kilometers: int, deliveries: int, cost_cents: int, cost_per_km_cents: int|null, cost_per_delivery_cents: int|null, currency: string}>
     */
    public function breakdown(string $from, string $to): array
    {
        $fromAt = new DateTimeImmutable($from.' 00:00:00');
        $toAt = new DateTimeImmutable($to.' 23:59:59');

        $departures = $this->departures->listBy(null, null, $fromAt, $toAt);
        $kmByVehicle = $this->telemetry->kmByVehicle($from, $to);

        $currency = (string) config('logistics-fleetops.currency', Money::DEFAULT_CURRENCY);

        // Costo por km de cada vehículo del período, memoizado (una sola vez por vehículo).
        /** @var array<string, int|null> $costPerKmByVehicle */
        $costPerKmByVehicle = [];

        /** @var array<string, array{corridor: string, departures: int, kilometers: int, deliveries: int, cost_cents: int}> $acc */
        $acc = [];

        foreach ($departures as $departure) {
            assert($departure instanceof Departure);

            $vehicleId = $departure->vehicleId();
            if (!array_key_exists($vehicleId, $costPerKmByVehicle)) {
                $totals = $this->vehicleCosts->vehicleTotals($vehicleId, $fromAt, $toAt);
                $currency = $totals['currency'];
                $costPerKmByVehicle[$vehicleId] = $this->allocator->costPerKmCents(
                    $totals['total_cents'],
                    $kmByVehicle[$vehicleId] ?? 0,
                );
            }

            $corridor = $this->corridorKey($departure);
            $km = $this->kmTraveled($departure);
            $cost = $this->allocator->routeCostCents($costPerKmByVehicle[$vehicleId], $km);
            $deliveries = max(1, count($departure->load()->items));

            if (!isset($acc[$corridor])) {
                $acc[$corridor] = [
                    'corridor' => $corridor,
                    'departures' => 0,
                    'kilometers' => 0,
                    'deliveries' => 0,
                    'cost_cents' => 0,
                ];
            }

            $acc[$corridor]['departures']++;
            $acc[$corridor]['kilometers'] += $km;
            $acc[$corridor]['deliveries'] += $deliveries;
            $acc[$corridor]['cost_cents'] += $cost;
        }

        $lines = [];
        foreach ($acc as $row) {
            $lines[] = [
                'corridor' => $row['corridor'],
                'departures' => $row['departures'],
                'kilometers' => $row['kilometers'],
                'deliveries' => $row['deliveries'],
                'cost_cents' => $row['cost_cents'],
                'cost_per_km_cents' => $this->allocator->costPerKmCents($row['cost_cents'], $row['kilometers']),
                'cost_per_delivery_cents' => $this->allocator->costPerDeliveryCents($row['cost_cents'], $row['deliveries']),
                'currency' => $currency,
            ];
        }

        usort($lines, static fn (array $a, array $b): int => $b['cost_cents'] <=> $a['cost_cents']);

        return $lines;
    }

    /**
     * Km recorridos de una salida, tolerante a datos de borde: si el odómetro de regreso es menor al de
     * salida (lectura inconsistente), la salida no aporta distancia (0 km) en lugar de romper la analítica.
     */
    private function kmTraveled(Departure $departure): int
    {
        try {
            return $departure->kmTraveled() ?? 0;
        } catch (FleetOpsException) {
            return 0;
        }
    }

    /**
     * Clave del corredor de una salida: la referencia de ruta, o el destino si falta, o '(unassigned)'.
     */
    private function corridorKey(Departure $departure): string
    {
        $routeRef = $departure->routeRef();
        if ($routeRef !== null && trim($routeRef) !== '') {
            return trim($routeRef);
        }

        $destination = $departure->destination();
        if ($destination !== null && trim($destination) !== '') {
            return trim($destination);
        }

        return self::UNASSIGNED;
    }
}
