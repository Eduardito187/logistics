<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Fleet\Application\Contract\DepartureRouteReader;
use stdClass;
use Throwable;

/**
 * Lee las rutas del día desde `execution_driver_routes` por el query builder, en solo lectura y sin
 * acoplar Flota al dominio de Ejecución. Tolerante: si la tabla no está (contexto extraído o sin migrar),
 * responde vacío y `my-crew` cae a la tripulación más reciente del día.
 *
 * @phpstan-import-type DepartureRoute from DepartureRouteReader
 */
final readonly class DbDepartureRouteReader implements DepartureRouteReader
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function onDate(string $date, array $vehicleIds): array
    {
        $vehicleIds = array_values(array_unique(array_filter($vehicleIds, static fn (string $id): bool => $id !== '')));
        if ($vehicleIds === []) {
            return [];
        }

        try {
            $rows = $this->connections->connection()->table('execution_driver_routes')
                ->whereDate('route_date', $date)
                ->whereIn('vehicle_id', $vehicleIds)
                ->orderByRaw('created_at DESC NULLS LAST')
                ->orderByDesc('id')
                ->get(['id', 'vehicle_id', 'status', 'driver_id'])
                ->all();
        } catch (Throwable) {
            return [];
        }

        $routes = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $driverId = $row->driver_id ?? null;

            $routes[] = [
                'route_id' => (string) $row->id,
                'vehicle_id' => (string) $row->vehicle_id,
                'status' => strtoupper((string) $row->status),
                'driver_id' => is_string($driverId) && $driverId !== '' ? $driverId : null,
            ];
        }

        return $routes;
    }
}
