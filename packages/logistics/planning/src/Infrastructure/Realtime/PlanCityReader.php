<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Realtime;

use Illuminate\Database\ConnectionInterface;
use Logistics\Network\Domain\ValueObject\Department;
use Throwable;

/**
 * Lectura de las CIUDADES (códigos de departamento) a las que pertenece un plan, para decidir por qué
 * canales de tiempo real se avisa su cambio.
 *
 * Mismo criterio con el que el planificador ve un plan en el admin (`ScopesToUserCity::scopeTripsToUserCity`):
 * el plan es de la ciudad donde su camión tiene la base, y también de las ciudades de origen y de destino de
 * los pedidos de sus paradas. Lee el estado GUARDADO del plan (por eso se consulta antes y después de
 * persistirlo: una parada quitada todavía debe avisar a su ciudad).
 *
 * Solo lectura por nombre de tabla, sin modelos ni dependencia del dominio de Flota o de Envíos. Dos
 * consultas por clave. Nunca lanza: si algo falla devuelve vacío (el aviso sale igual por `planning.all`);
 * dentro de una transacción corre en un savepoint para no abortar la transacción externa.
 */
final readonly class PlanCityReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    /**
     * Ciudades del plan según lo guardado en este momento.
     *
     * @return list<string>
     */
    public function citiesOf(string $planId, string $vehicleId): array
    {
        // Nunca se compara contra una columna uuid un valor que no tenga forma de UUID (22P02).
        $planId = preg_match(self::UUID_PATTERN, $planId) === 1 ? $planId : null;
        $vehicleId = preg_match(self::UUID_PATTERN, $vehicleId) === 1 ? $vehicleId : null;
        if ($planId === null && $vehicleId === null) {
            return [];
        }

        try {
            return $this->connection->transactionLevel() > 0
                ? $this->readInSavepoint($planId, $vehicleId)
                : $this->read($planId, $vehicleId);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Cada ciudad de la red: para los hechos que son del día entero y no de una ciudad (la corrida
     * automática). Sale del catálogo de departamentos, sin consultar nada.
     *
     * @return list<string>
     */
    public function allCities(): array
    {
        return array_map(static fn (Department $department): string => $department->value, Department::cases());
    }

    /**
     * Misma lectura dentro de una transacción ya abierta: corre en un savepoint, así un fallo de la consulta
     * no aborta la transacción externa.
     *
     * @return list<string>
     */
    private function readInSavepoint(?string $planId, ?string $vehicleId): array
    {
        return $this->connection->transaction(fn (): array => $this->read($planId, $vehicleId));
    }

    /**
     * @return list<string>
     */
    private function read(?string $planId, ?string $vehicleId): array
    {
        $cities = [];

        if ($vehicleId !== null) {
            $base = $this->connection->table('fleet_vehicles as v')
                ->join('network_nodes as n', 'n.id', '=', 'v.home_base_node_id')
                ->where('v.id', $vehicleId)
                ->value('n.department');
            self::collect($cities, $base);
        }

        if ($planId !== null) {
            $rows = $this->connection->table('planning_plan_stops as ps')
                ->join('shipments as s', 's.id', '=', 'ps.shipment_id')
                ->where('ps.plan_id', $planId)
                ->distinct()
                ->get(['s.origin_department', 's.destination_department']);

            foreach ($rows as $row) {
                $data = (array) $row;
                self::collect($cities, $data['origin_department'] ?? null);
                self::collect($cities, $data['destination_department'] ?? null);
            }
        }

        return array_keys($cities);
    }

    /**
     * @param array<string, true> $cities
     */
    private static function collect(array &$cities, mixed $department): void
    {
        if (!is_string($department)) {
            return;
        }

        $code = strtolower(trim($department));
        if ($code !== '') {
            $cities[$code] = true;
        }
    }
}
