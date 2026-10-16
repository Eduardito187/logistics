<?php

declare(strict_types=1);

namespace Logistics\Network\Infrastructure\Persistence\Eloquent;

use Logistics\Network\Domain\Contract\ReceivingDockRepository;
use Logistics\Network\Domain\ReceivingDock;
use Logistics\Network\Domain\ValueObject\DockCapacity;
use Logistics\Network\Domain\ValueObject\DockCode;
use Logistics\Network\Domain\ValueObject\NodeId;
use Logistics\Network\Domain\ValueObject\ReceivingDockId;

/**
 * Implementación Eloquent del repositorio de andenes de recepción. Mapea el agregado ReceivingDock hacia
 * y desde la fila receiving_docks.
 */
final class EloquentReceivingDockRepository implements ReceivingDockRepository
{
    public function nextIdentity(): ReceivingDockId
    {
        return ReceivingDockId::generate();
    }

    public function save(ReceivingDock $dock): void
    {
        $id = $dock->id()->toString();

        $record = ReceivingDockRecord::query()->find($id) ?? new ReceivingDockRecord;
        $record->id = $id;
        $record->warehouse_id = $dock->warehouseId()->toString();
        $record->code = $dock->code()->toString();
        $record->name = $dock->name();
        $record->appointments_per_slot = $dock->capacity()->appointmentsPerSlot;
        $record->active = $dock->isActive();
        $record->save();
    }

    public function findById(ReceivingDockId $id): ?ReceivingDock
    {
        $record = ReceivingDockRecord::query()->find($id->toString());

        return $record === null ? null : $this->toDomain($record);
    }

    public function findByCode(NodeId $warehouseId, DockCode $code): ?ReceivingDock
    {
        $record = ReceivingDockRecord::query()
            ->where('warehouse_id', $warehouseId->toString())
            ->where('code', $code->toString())
            ->first();

        return $record === null ? null : $this->toDomain($record);
    }

    /**
     * @return list<ReceivingDock>
     */
    public function byWarehouse(NodeId $warehouseId): array
    {
        return ReceivingDockRecord::query()
            ->where('warehouse_id', $warehouseId->toString())
            ->orderBy('code')
            ->get()
            ->map(fn (ReceivingDockRecord $record): ReceivingDock => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(ReceivingDockRecord $record): ReceivingDock
    {
        return ReceivingDock::fromState(
            ReceivingDockId::fromString($record->id),
            NodeId::fromString($record->warehouse_id),
            DockCode::fromString($record->code),
            $record->name,
            DockCapacity::of($record->appointments_per_slot),
            $record->active,
        );
    }
}
