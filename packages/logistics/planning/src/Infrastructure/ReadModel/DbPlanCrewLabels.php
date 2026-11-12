<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Planning\Application\Contract\PlanCrewLabels;
use Throwable;

/**
 * Adaptador read-side de placa y chofer de los planes, por query builder sobre tablas de otros contextos
 * (solo lectura, sin modelos ni dependencia de su dominio). Cada lectura va EN LOTE:
 *
 *  - placas: una consulta whereIn sobre fleet_vehicles;
 *  - choferes: una consulta sobre execution_driver_routes ⨝ fleet_drivers (el chofer GRABADO en la ruta
 *    del día, si el plan ya se despachó) y otra sobre fleet_crews ⨝ fleet_drivers (la tripulación armada
 *    para ese camión y día) como respaldo. Si hay varias tripulaciones, gana la más reciente.
 *
 * Degradación con gracia: si una lectura falla (tabla ausente en un entorno a medio migrar) devuelve mapa
 * vacío; dentro de una transacción la lectura va en un savepoint para no abortar la transacción externa.
 */
final readonly class DbPlanCrewLabels implements PlanCrewLabels
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function platesFor(array $vehicleIds): array
    {
        $ids = array_values(array_unique(array_filter($vehicleIds, static fn (string $id): bool => $id !== '')));
        if ($ids === []) {
            return [];
        }

        return $this->safely(function () use ($ids): array {
            $plates = [];
            foreach ($this->connection->table('fleet_vehicles')->whereIn('id', $ids)->get(['id', 'plate']) as $row) {
                $plate = $row->plate ?? null;
                if (is_scalar($plate) && trim((string) $plate) !== '') {
                    $plates[(string) $row->id] = trim((string) $plate);
                }
            }

            return $plates;
        });
    }

    public function driverNamesFor(array $slots): array
    {
        if ($slots === []) {
            return [];
        }

        $vehicleIds = array_values(array_unique(array_column($slots, 'vehicle_id')));
        $dates = array_values(array_unique(array_column($slots, 'plan_date')));
        $wanted = [];
        foreach ($slots as $slot) {
            $wanted[$this->key($slot['vehicle_id'], $slot['plan_date'])] = true;
        }

        // Respaldo: tripulación armada (la más reciente por camión y día gana: se recorre de vieja a nueva).
        $names = $this->safely(fn (): array => $this->collect(
            $this->connection->table('fleet_crews as c')
                ->join('fleet_drivers as d', 'd.id', '=', 'c.driver_id')
                ->whereIn('c.vehicle_id', $vehicleIds)
                ->whereIn('c.assembled_for', $dates)
                ->orderBy('c.created_at')
                ->get(['c.vehicle_id as vehicle_id', 'c.assembled_for as day', 'd.name as name'])
                ->all(),
            $wanted,
        ));

        // Preferente: chofer grabado en la ruta del día (pisa al de la tripulación).
        $fromRoutes = $this->safely(fn (): array => $this->collect(
            $this->connection->table('execution_driver_routes as r')
                ->join('fleet_drivers as d', 'd.id', '=', 'r.driver_id')
                ->whereIn('r.vehicle_id', $vehicleIds)
                ->whereIn('r.route_date', $dates)
                ->get(['r.vehicle_id as vehicle_id', 'r.route_date as day', 'd.name as name'])
                ->all(),
            $wanted,
        ));

        return array_replace($names, $fromRoutes);
    }

    /**
     * Mapa clave(vehículo, día) → nombre, solo para los pares pedidos.
     *
     * @param  array<array-key, mixed> $rows
     * @param  array<string, true>     $wanted
     * @return array<string, string>
     */
    private function collect(array $rows, array $wanted): array
    {
        $names = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $vehicleId = is_scalar($data['vehicle_id'] ?? null) ? (string) $data['vehicle_id'] : '';
            $day = is_scalar($data['day'] ?? null) ? substr((string) $data['day'], 0, 10) : '';
            $name = is_scalar($data['name'] ?? null) ? trim((string) $data['name']) : '';
            $key = $this->key($vehicleId, $day);
            if ($name !== '' && isset($wanted[$key])) {
                $names[$key] = $name;
            }
        }

        return $names;
    }

    private function key(string $vehicleId, string $day): string
    {
        return $vehicleId.PlanCrewLabels::SLOT_SEPARATOR.$day;
    }

    /**
     * Ejecuta una lectura tolerante: ante error devuelve mapa vacío. Dentro de una transacción abierta la
     * lectura corre en un savepoint, así un fallo no deja abortada la transacción externa (PostgreSQL).
     *
     * @param  callable(): array<string, string> $read
     * @return array<string, string>
     */
    private function safely(callable $read): array
    {
        try {
            if ($this->connection->transactionLevel() > 0) {
                /** @var array<string, string> $result */
                $result = $this->connection->transaction(static fn (): array => $read());

                return $result;
            }

            return $read();
        } catch (Throwable) {
            return [];
        }
    }
}
