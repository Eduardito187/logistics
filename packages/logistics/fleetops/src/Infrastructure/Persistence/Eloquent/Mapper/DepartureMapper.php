<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\Departure;
use Logistics\FleetOps\Domain\ValueObject\DepartureId;
use Logistics\FleetOps\Domain\ValueObject\DepartureStatus;
use Logistics\FleetOps\Domain\ValueObject\Load;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\DepartureRecord;

/**
 * Traduce entre el agregado Departure (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class DepartureMapper
{
    public function toDomain(DepartureRecord $record): Departure
    {
        return Departure::reconstitute(
            DepartureId::fromString($record->id),
            $record->vehicle_id,
            $record->driver_id,
            array_values($record->helper_ids),
            $record->destination,
            $record->route_ref,
            Load::of($record->load_description, $record->load_weight_kg, array_values($record->load_items)),
            $record->odometer_out_km === null ? null : Odometer::km($record->odometer_out_km),
            DepartureStatus::from($record->status),
            $record->odometer_in_km === null ? null : Odometer::km($record->odometer_in_km),
            $record->departed_at->toDateTimeImmutable(),
            $record->returned_at?->toDateTimeImmutable(),
            $record->note,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string|null, helper_ids: list<string>, destination: string|null, route_ref: string|null, load_description: string|null, load_weight_kg: float|null, load_items: list<array{name: string, qty: int}>, odometer_out_km: int|null, odometer_in_km: int|null, status: string, departed_at: DateTimeImmutable, returned_at: DateTimeImmutable|null, note: string|null}
     */
    public function toRow(Departure $departure): array
    {
        $load = $departure->load();

        return [
            'id' => $departure->id()->toString(),
            'vehicle_id' => $departure->vehicleId(),
            'driver_id' => $departure->driverId(),
            'helper_ids' => array_values($departure->helperIds()),
            'destination' => $departure->destination(),
            'route_ref' => $departure->routeRef(),
            'load_description' => $load->description,
            'load_weight_kg' => $load->weightKg,
            'load_items' => array_values($load->items),
            'odometer_out_km' => $departure->odometerOut()?->km,
            'odometer_in_km' => $departure->odometerIn()?->km,
            'status' => $departure->status()->value,
            'departed_at' => $departure->departedAt(),
            'returned_at' => $departure->returnedAt(),
            'note' => $departure->note(),
        ];
    }
}
