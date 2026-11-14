<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Model\Vehicle;
use Logistics\Fleet\Domain\ValueObject\Capacity;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\PlateNumber;
use Logistics\Fleet\Domain\ValueObject\VehicleFitness;
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
            VehicleStatus::fromLoose($model->status),
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
            $this->fitnessFrom($model),
        );
    }

    /**
     * Aptitud desde las columnas `unfit_*`: sin `unfit_since` el vehículo está apto.
     */
    private function fitnessFrom(VehicleModel $model): VehicleFitness
    {
        if ($model->unfit_since === null || $model->unfit_since === '') {
            return VehicleFitness::fit();
        }

        return VehicleFitness::unfit(
            new DateTimeImmutable($model->unfit_since),
            $model->unfit_reason ?? '',
            $model->unfit_inspection_id,
            array_values(array_map('strval', $model->unfit_items ?? [])),
        );
    }

    /**
     * @return array{id: string, plate: string, capacity_kg: int, capacity_m3: float, home_base_node_id: string, status: string, documents: list<array{type: string, expires_on: string}>, make: string|null, model: string|null, model_year: int|null, vin: string|null, engine_number: string|null, color: string|null, photo_front: string|null, photo_back: string|null, photo_left: string|null, photo_right: string|null, unfit_since: string|null, unfit_reason: string|null, unfit_inspection_id: string|null, unfit_items: list<string>|null}
     */
    public function toRow(Vehicle $vehicle): array
    {
        $fitness = $vehicle->fitness();

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
            'unfit_since' => $fitness->unfitSince?->format('Y-m-d'),
            'unfit_reason' => $fitness->reason,
            'unfit_inspection_id' => $fitness->inspectionId,
            'unfit_items' => $fitness->isUnfit() ? $fitness->failedItems : null,
        ];
    }
}
