<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\PreTripInspection;
use Logistics\Fleet\Domain\ValueObject\DriverId;
use Logistics\Fleet\Domain\ValueObject\PreTripChecklist;
use Logistics\Fleet\Domain\ValueObject\PreTripInspectionId;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\PreTripInspectionModel;

/**
 * Traduce entre el agregado PreTripInspection (dominio) y su fila Eloquent.
 *
 * `passed` se guarda desnormalizado para poder filtrar/ordenar inspecciones en SQL sin reconstruir el
 * VO; al reconstituir se ignora, porque el dominio lo deriva de los ítems del checklist.
 */
final class PreTripInspectionMapper
{
    public function toDomain(PreTripInspectionModel $model): PreTripInspection
    {
        return PreTripInspection::reconstitute(
            PreTripInspectionId::fromString($model->id),
            VehicleId::fromString($model->vehicle_id),
            DriverId::fromString($model->driver_id),
            PreTripChecklist::of($model->items),
            new DateTimeImmutable($model->performed_on),
            $model->notes,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, driver_id: string, items: array<string, bool>, passed: bool, performed_on: string, notes: string|null}
     */
    public function toRow(PreTripInspection $inspection): array
    {
        return [
            'id' => $inspection->id()->toString(),
            'vehicle_id' => $inspection->vehicleId()->toString(),
            'driver_id' => $inspection->driverId()->toString(),
            'items' => $inspection->checklist()->toArray(),
            'passed' => $inspection->passed(),
            'performed_on' => $inspection->performedOn()->format('Y-m-d'),
            'notes' => $inspection->notes(),
        ];
    }
}
