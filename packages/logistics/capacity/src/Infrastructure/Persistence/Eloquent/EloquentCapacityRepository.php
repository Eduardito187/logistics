<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Domain\CapacityBucket;
use Logistics\Capacity\Domain\Contract\CapacityRepository;
use Logistics\Capacity\Domain\ValueObject\BucketScope;
use Logistics\Capacity\Domain\ValueObject\CapacityBucketId;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\Quantity;
use Logistics\Capacity\Domain\ValueObject\ScopeKind;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Implementación Eloquent del repositorio de cupos. Mapea el agregado CapacityBucket hacia y desde la
 * fila capacity_buckets. total/used se guardan como decimal(12,3); el mapper los pasa por Quantity.
 */
final class EloquentCapacityRepository implements CapacityRepository
{
    public function nextIdentity(): CapacityBucketId
    {
        return CapacityBucketId::generate();
    }

    public function save(CapacityBucket $bucket): void
    {
        $id = $bucket->id()->toString();

        $record = CapacityBucketRecord::query()->find($id) ?? new CapacityBucketRecord;
        $record->id = $id;
        $record->scope_kind = $bucket->scope()->kind->value;
        $record->node_id = $bucket->scope()->nodeId;
        $record->zone_id = $bucket->scope()->zoneId;
        $record->municipality_id = $bucket->scope()->municipalityId;
        $record->warehouse_id = $bucket->scope()->warehouseId;
        $record->dock_id = $bucket->scope()->dockId;
        $record->slot_date = $bucket->date()->toString();
        $record->time_slot = $bucket->slot()->toString();
        $record->unit = $bucket->unit()->value;
        $record->total = $this->formatQuantity($bucket->total());
        $record->used = $this->formatQuantity($bucket->used());
        $record->save();
    }

    public function findById(CapacityBucketId $id): ?CapacityBucket
    {
        $record = CapacityBucketRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function findBucket(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket {
        return $this->findBucketRecord($scope, $date, $slot, $unit, lockForUpdate: false);
    }

    public function findBucketForUpdate(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
    ): ?CapacityBucket {
        return $this->findBucketRecord($scope, $date, $slot, $unit, lockForUpdate: true);
    }

    /**
     * Resuelve el bucket por su clave natural, con o sin lock pesimista. Con lock, debe correr dentro de
     * una transacción (la del CommandBus); fuera de transacción el lock no tiene efecto útil.
     */
    private function findBucketRecord(
        BucketScope $scope,
        SlotDate $date,
        TimeSlot $slot,
        CapacityUnit $unit,
        bool $lockForUpdate,
    ): ?CapacityBucket {
        $query = CapacityBucketRecord::query()
            ->where('scope_kind', $scope->kind->value)
            ->where('slot_date', $date->toString())
            ->where('time_slot', $slot->toString())
            ->where('unit', $unit->value);

        // Las columnas de scope se casan según su eje: en despacho exactamente una de node/zone/muni va
        // poblada; en recepción va el almacén y, opcionalmente, el andén. Las columnas del otro eje van
        // NULL, así que el match reproduce la clave natural exacta del bucket.
        $this->whereScopeColumn($query, 'node_id', $scope->nodeId);
        $this->whereScopeColumn($query, 'zone_id', $scope->zoneId);
        $this->whereScopeColumn($query, 'municipality_id', $scope->municipalityId);
        $this->whereScopeColumn($query, 'warehouse_id', $scope->warehouseId);
        $this->whereScopeColumn($query, 'dock_id', $scope->dockId);

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<CapacityBucket>
     */
    public function byDate(SlotDate $date): array
    {
        return CapacityBucketRecord::query()
            ->where('slot_date', $date->toString())
            ->orderBy('time_slot')
            ->orderBy('unit')
            ->get()
            ->map(fn (CapacityBucketRecord $record): CapacityBucket => $this->toDomain($record))
            ->values()
            ->all();
    }

    /**
     * Cupos de RECEPCIÓN (inbound) de un almacén central en una fecha, ordenados de forma estable (por
     * andén, franja y unidad). Alimenta el calendario de andenes del cockpit de inbound.
     *
     * @return list<CapacityBucket>
     */
    public function inboundByWarehouseAndDate(string $warehouseId, SlotDate $date): array
    {
        return CapacityBucketRecord::query()
            ->where('scope_kind', ScopeKind::Inbound->value)
            ->where('warehouse_id', $warehouseId)
            ->where('slot_date', $date->toString())
            ->orderByRaw('dock_id NULLS FIRST')
            ->orderBy('time_slot')
            ->orderBy('unit')
            ->get()
            ->map(fn (CapacityBucketRecord $record): CapacityBucket => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(CapacityBucketRecord $record): CapacityBucket
    {
        return CapacityBucket::fromState(
            CapacityBucketId::fromString($record->id),
            BucketScope::fromColumns(
                $record->scope_kind,
                $record->node_id,
                $record->zone_id,
                $record->municipality_id,
                $record->warehouse_id,
                $record->dock_id,
            ),
            // La columna es date; se recorta por si el driver devuelve 'Y-m-d H:i:s'.
            SlotDate::fromString(substr($record->slot_date, 0, 10)),
            TimeSlot::fromString($record->time_slot),
            CapacityUnit::from($record->unit),
            Quantity::of((float) $record->total),
            Quantity::of((float) $record->used),
        );
    }

    /**
     * Casa una columna de scope por su valor, o exige NULL cuando el eje no la usa. Mantiene la clave
     * natural exacta del bucket sin repetir el ternario por columna.
     *
     * @param Builder<CapacityBucketRecord> $query
     */
    private function whereScopeColumn(Builder $query, string $column, ?string $value): void
    {
        $value === null
            ? $query->whereNull($column)
            : $query->where($column, $value);
    }

    /**
     * Formatea una Quantity como decimal con 3 decimales, el formato exacto de la columna decimal(12,3).
     */
    private function formatQuantity(Quantity $quantity): string
    {
        return number_format($quantity->value(), 3, '.', '');
    }
}
