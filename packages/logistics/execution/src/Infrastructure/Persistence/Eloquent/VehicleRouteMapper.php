<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\ArrivalSource;
use Logistics\Execution\Domain\ValueObject\RouteDate;
use Logistics\Execution\Domain\ValueObject\RouteId;
use Logistics\Execution\Domain\ValueObject\RouteLifecycleSnapshot;
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
            'started_at' => $route->startedAt()?->format(DateTimeInterface::ATOM),
            'closed_at' => $route->closedAt()?->format(DateTimeInterface::ATOM),
            'start_odometer_km' => $route->startOdometerKm(),
            'close_odometer_km' => $route->closeOdometerKm(),
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
            'arrived_at' => $stop->arrivedAt()?->format(DateTimeInterface::ATOM),
            'arrival_source' => $stop->arrivalSource()?->value,
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
            RouteStatus::fromLoose($record->status),
            $stops,
            new RouteLifecycleSnapshot(
                self::date($record->started_at),
                self::date($record->closed_at),
                $record->start_odometer_km !== null ? (float) $record->start_odometer_km : null,
                $record->close_odometer_km !== null ? (float) $record->close_odometer_km : null,
            ),
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
            RouteStopStatus::fromLoose($record->status),
            $record->settled_at !== null ? new DateTimeImmutable($record->settled_at) : null,
            self::date($record->arrived_at),
            $record->arrival_source !== null ? ArrivalSource::tryFrom($record->arrival_source) : null,
        );
    }

    private static function date(?string $value): ?DateTimeImmutable
    {
        return $value !== null && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
