<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\WorkShift;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;

/**
 * Traduce entre el agregado Crew (dominio) y su fila Eloquent.
 */
final class CrewMapper
{
    public function toDomain(CrewModel $model): Crew
    {
        $shift = is_string($model->shift_starts_at) && is_string($model->shift_ends_at)
            ? WorkShift::fromArray(['starts_at' => $model->shift_starts_at, 'ends_at' => $model->shift_ends_at])
            : null;

        return Crew::reconstitute(
            CrewId::fromString($model->id),
            DriverId::fromString($model->driver_id),
            array_values($model->helper_ids),
            VehicleId::fromString($model->vehicle_id),
            new DateTimeImmutable($model->assembled_for),
            $shift,
        );
    }

    /**
     * @return array{id: string, driver_id: string, helper_ids: list<string>, vehicle_id: string, assembled_for: string, shift_starts_at: string|null, shift_ends_at: string|null}
     */
    public function toRow(Crew $crew): array
    {
        $shift = $crew->shift();

        return [
            'id' => $crew->id()->toString(),
            'driver_id' => $crew->driverId()->toString(),
            'helper_ids' => $crew->helperIds(),
            'vehicle_id' => $crew->vehicleId()->toString(),
            'assembled_for' => $crew->assembledFor()->format('Y-m-d'),
            'shift_starts_at' => $shift?->startsAt->format(DateTimeImmutable::ATOM),
            'shift_ends_at' => $shift?->endsAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
