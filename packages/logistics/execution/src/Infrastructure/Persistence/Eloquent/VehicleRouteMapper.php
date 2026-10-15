<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteStatus;
use Logistics\Execution\Domain\ValueObject\RouteStopId;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;

/**
 * Traduce entre el agregado VehicleRoute (con sus paradas) y sus filas Eloquent. Toda la conversión
 * a/desde primitivos vive acá: ni el dominio ni el repositorio conocen la forma de las tablas.
 */
final class VehicleRouteMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRouteRow(VehicleRoute $route): array
    {
        return [
            'id' => $route->id()->toString(),
            'vehicle_id' => $route->vehicleId(),
            'route_date' => $route->date()->toString(),
            'status' => $route->status()->value,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toStopRow(VehicleRoute $route, RouteStop $stop): array
    {
        return [
            'id' => $stop->id()->toString(),
            'route_id' => $route->id()->toString(),
            'shipment_id' => $stop->shipmentId(),
            'sequence' => $stop->sequence(),
            'order_reference' => $stop->orderReference(),
            'customer_name' => $stop->customerName(),
            'address' => $stop->address(),
            'eta' => $stop->etaAt()?->format(DateTimeInterface::ATOM),
            'lat' => $stop->lat(),
            'lng' => $stop->lng(),
            'status' => $stop->status()->value,
            'settled_at' => $stop->settledAt()?->format(DateTimeInterface::ATOM),
        ];
    }

    /**
     * Reconstruye el agregado completo (con sus paradas) desde sus filas.
     */
    public function toDomain(VehicleRouteRecord $record): VehicleRoute
    {
        $stops = [];
        foreach ($record->stops as $stopRecord) {
            $stops[] = $this->toStopDomain($stopRecord);
        }

        return VehicleRoute::reconstitute(
            RouteId::fromString($record->id),
            $record->vehicle_id,
            RouteDate::fromString($record->route_date),
            RouteStatus::from($record->status),
            $stops,
        );
    }

    private function toStopDomain(RouteStopRecord $record): RouteStop
    {
        return RouteStop::reconstitute(
            RouteStopId::fromString($record->id),
            $record->shipment_id,
            $record->sequence,
            $record->order_reference,
            $record->customer_name,
            $record->address,
            $record->eta !== null ? new DateTimeImmutable($record->eta) : null,
            $record->lat !== null ? (float) $record->lat : null,
            $record->lng !== null ? (float) $record->lng : null,
            RouteStopStatus::from($record->status),
            $record->settled_at !== null ? new DateTimeImmutable($record->settled_at) : null,
        );
    }
}
