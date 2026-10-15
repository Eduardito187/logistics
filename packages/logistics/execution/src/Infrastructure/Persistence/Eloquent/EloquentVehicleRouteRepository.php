<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;

/**
 * Implementación Eloquent del repositorio de rutas del chofer.
 *
 * save() es un upsert transaccional: reescribe la fila de la ruta y regenera sus paradas (borrar e
 * insertar) para que la persistencia refleje exactamente el estado del agregado, que es la fuente de
 * verdad del orden y del estado de cada parada.
 */
final class EloquentVehicleRouteRepository implements VehicleRouteRepository
{
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

            // Regenera las paradas: el agregado es la fuente de verdad de orden y estado.
            RouteStopRecord::query()->where('route_id', $routeId)->delete();

            foreach ($route->stops() as $stop) {
                RouteStopRecord::query()->create($this->mapper->toStopRow($route, $stop));
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
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findOpenByVehicleId(string $vehicleId): ?VehicleRoute
    {
        $record = VehicleRouteRecord::query()
            ->with('stops')
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', [RouteStatus::Planned->value, RouteStatus::InProgress->value])
            ->orderByDesc('route_date')
            ->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipmentId(string $shipmentId): ?VehicleRoute
    {
        // La parada apunta a su ruta; se busca la más reciente que incluye el envío y se carga completa.
        $routeId = RouteStopRecord::query()
            ->where('shipment_id', $shipmentId)
            ->orderByDesc('created_at')
            ->value('route_id');

        if (!is_string($routeId) || $routeId === '') {
            return null;
        }

        return $this->findById(RouteId::fromString($routeId));
    }
}
