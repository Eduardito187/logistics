<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;

/**
 * Implementación Eloquent del repositorio de rutas del chofer.
 *
 * save() es un upsert transaccional: reescribe la fila de la ruta y regenera sus paradas (borrar e
 * insertar) para que la persistencia refleje exactamente el estado del agregado, que es la fuente de
 * verdad del orden y del estado de cada parada.
 */
final class EloquentVehicleRouteRepository implements VehicleRouteRepository
{
    private const string STOPS_TABLE = 'execution_driver_route_stops';

    public function __construct(
        private readonly VehicleRouteMapper $mapper,
        private readonly ConnectionResolverInterface $connections,
    ) {}

    public function nextIdentity(): RouteId
    {
        return RouteId::generate();
    }

    public function save(VehicleRoute $route): void
    {
        $this->connections->connection()->transaction(function () use ($route): void {
            VehicleRouteRecord::query()->updateOrCreate(
                ['id' => $route->id()->toString()],
                $this->mapper->toRouteRow($route),
            );

            $routeId = $route->id()->toString();

            // Regenera las paradas: el agregado es la fuente de verdad de orden y estado. Se conserva el
            // `created_at` de las que ya existían: sin esto cada guardado lo pisaba con la hora actual y se
            // perdía cuándo entró realmente cada parada.
            $createdAt = $this->connections->connection()
                ->table(self::STOPS_TABLE)
                ->where('route_id', $routeId)
                ->pluck('created_at', 'id')
                ->all();

            RouteStopRecord::query()->where('route_id', $routeId)->delete();

            foreach ($route->stops() as $stop) {
                $row = $this->mapper->toStopRow($route, $stop);
                $existing = $createdAt[$stop->id()->toString()] ?? null;
                if (is_string($existing) && $existing !== '') {
                    $row['created_at'] = $existing;
                }

                RouteStopRecord::query()->create($row);
            }
        });
    }

    public function findById(RouteId $id): ?VehicleRoute
    {
        $record = VehicleRouteRecord::query()
            ->with('stops')
            ->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByVehicleAndDate(string $vehicleId, RouteDate $date): ?VehicleRoute
    {
        $record = VehicleRouteRecord::query()
            ->with('stops')
            ->where('vehicle_id', $vehicleId)
            ->where('route_date', $date->toString())
            // Un camión puede tener VARIAS rutas el mismo día (una por salida). Manda la que está abierta;
            // si no hay ninguna abierta, la más reciente.
            ->orderByRaw("CASE WHEN status IN ('PLANNED', 'IN_PROGRESS', 'SUSPENDED') THEN 0 ELSE 1 END")
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findOpenByVehicleId(string $vehicleId): ?VehicleRoute
    {
        $today = date('Y-m-d');

        $record = VehicleRouteRecord::query()
            ->with('stops')
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', [RouteStatus::Planned->value, RouteStatus::InProgress->value])
            // La hoja vigente es la del día: primero la de hoy, si no la abierta anterior más cercana, y
            // recién después una futura. Antes mandaba la de fecha MÁS ALTA, así que una ruta ya armada para
            // un día futuro tapaba a la de hoy.
            ->orderByRaw('CASE WHEN route_date > ? THEN 1 ELSE 0 END', [$today])
            ->orderByRaw('ABS(route_date - CAST(? AS date))', [$today])
            ->orderByDesc('created_at')
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipmentId(string $shipmentId): ?VehicleRoute
    {
        // La parada apunta a su ruta. Si el envío figura en más de una (quedó en una ruta vieja y se volvió a
        // asignar), manda la ruta donde su parada sigue PENDIENTE; a igualdad, la más reciente.
        $routeId = RouteStopRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [RouteStopStatus::Pending->value])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('route_id');

        if (!is_string($routeId) || $routeId === '') {
            return null;
        }

        return $this->findById(RouteId::fromString($routeId));
    }
}
