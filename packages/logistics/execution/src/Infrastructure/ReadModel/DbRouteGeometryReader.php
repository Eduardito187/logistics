<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\RouteGeometryReader;

/**
 * Lector de la geometría de ruta por lectura directa de `planning_plans`.
 *
 * El plan y la ruta del chofer comparten identidad por (vehículo, día): se busca el plan del mismo camión
 * y fecha y se devuelve su columna `route_geometry` (jsonb) ya en forma primitiva (el esquema RouteGeometry
 * que escribe planning). No usa el modelo Eloquent de planning: lee la columna con el query builder, así
 * Ejecución no se acopla al dominio del otro contexto. Null si no hay plan o aún no tiene geometría.
 */
final readonly class DbRouteGeometryReader implements RouteGeometryReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forVehicleAndDate(string $vehicleId, string $routeDate): ?array
    {
        if (trim($vehicleId) === '' || trim($routeDate) === '') {
            return null;
        }

        $raw = $this->connection->table('planning_plans')
            ->where('vehicle_id', $vehicleId)
            ->where('plan_date', $routeDate)
            ->value('route_geometry');

        if ($raw === null) {
            return null;
        }

        // El driver de Postgres puede devolver el jsonb ya decodificado (array) o como string JSON crudo.
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded) || !isset($decoded['coordinates']) || !is_array($decoded['coordinates'])) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }
}
