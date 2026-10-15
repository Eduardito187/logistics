<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Fleet\Domain\ValueObject\VehicleStatus;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;

/**
 * Traduce entre el agregado Vehicle (dominio) y su fila Eloquent. Es el único punto que conoce ambos.
 */
final class VehicleMapper
{
    public function toDomain(VehicleModel $model): Vehicle
    {
        $documents = array_map(
            static fn (array $doc): DocumentExpiry => DocumentExpiry::of(
                $doc['type'],
                new DateTimeImmutable($doc['expires_on']),
            ),
            $model->documents,
        );

        return Vehicle::reconstitute(
            VehicleId::fromString($model->id),
            PlateNumber::of($model->plate),
            Capacity::of($model->capacity_kg, $model->capacity_m3),
            $model->home_base_node_id,
            VehicleStatus::from($model->status),
            array_values($documents),
            $model->make,
            $model->model,
            $model->model_year,
            $model->vin,
            $model->engine_number,
            $model->color,
            $model->photo_front,
            $model->photo_back,
            $model->photo_left,
            $model->photo_right,
        );
    }

    /**
     * @return array{id: string, plate: string, capacity_kg: int, capacity_m3: float, home_base_node_id: string, status: string, documents: list<array{type: string, expires_on: string}>, make: string|null, model: string|null, model_year: int|null, vin: string|null, engine_number: string|null, color: string|null, photo_front: string|null, photo_back: string|null, photo_left: string|null, photo_right: string|null}
     */
    public function toRow(Vehicle $vehicle): array
    {
        return [
            'id' => $vehicle->id()->toString(),
            'plate' => $vehicle->plate()->value,
            'capacity_kg' => $vehicle->capacity()->kilograms,
            'capacity_m3' => $vehicle->capacity()->cubicMeters,
            'home_base_node_id' => $vehicle->homeBaseNodeId(),
            'status' => $vehicle->status()->value,
            'documents' => array_values(array_map(static fn (DocumentExpiry $document) => $document->toArray(), $vehicle->documents())),
            'make' => $vehicle->make(),
            'model' => $vehicle->model(),
            'model_year' => $vehicle->modelYear(),
            'vin' => $vehicle->vin(),
            'engine_number' => $vehicle->engineNumber(),
            'color' => $vehicle->color(),
            'photo_front' => $vehicle->photoFront(),
            'photo_back' => $vehicle->photoBack(),
            'photo_left' => $vehicle->photoLeft(),
            'photo_right' => $vehicle->photoRight(),
        ];
    }
}
