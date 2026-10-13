<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Crew;
use Logistics\Fleet\Domain\ValueObject\CrewId;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;

/**
 * Traduce entre el agregado Crew (dominio) y su fila Eloquent.
 */
final class CrewMapper
{
    public function toDomain(CrewModel $model): Crew
    {
        return Crew::reconstitute(
            CrewId::fromString($model->id),
            DriverId::fromString($model->driver_id),
            array_values($model->helper_ids),
            VehicleId::fromString($model->vehicle_id),
            new DateTimeImmutable($model->assembled_for),
        );
    }

    /**
     * @return array{id: string, driver_id: string, helper_ids: list<string>, vehicle_id: string, assembled_for: string}
     */
    public function toRow(Crew $crew): array
    {
        return [
            'id' => $crew->id()->toString(),
            'driver_id' => $crew->driverId()->toString(),
            'helper_ids' => $crew->helperIds(),
            'vehicle_id' => $crew->vehicleId()->toString(),
            'assembled_for' => $crew->assembledFor()->format('Y-m-d'),
        ];
    }
}
