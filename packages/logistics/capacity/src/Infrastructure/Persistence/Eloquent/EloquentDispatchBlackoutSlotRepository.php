<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutSlotRepository;
use Logistics\Capacity\Domain\DispatchBlackoutSlot;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutSlotId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Implementación Eloquent del repositorio de franjas horarias bloqueadas de despacho. Mapea el agregado
 * DispatchBlackoutSlot hacia y desde la fila capacity_blocked_slots.
 */
final class EloquentDispatchBlackoutSlotRepository implements DispatchBlackoutSlotRepository
{
    public function nextIdentity(): DispatchBlackoutSlotId
    {
        return DispatchBlackoutSlotId::generate();
    }

    public function save(DispatchBlackoutSlot $slot): void
    {
        $id = $slot->id()->toString();

        $record = DispatchBlackoutSlotRecord::query()->find($id) ?? new DispatchBlackoutSlotRecord;
        $record->id = $id;
        $record->slot_date = $slot->date()->toString();
        $record->time_slot = $slot->slot()->toString();
        $record->municipality_id = $slot->municipalityId();
        $record->reason = $slot->reason();
        $record->created_by = $slot->createdBy();
        $record->save();
    }

    public function remove(DispatchBlackoutSlot $slot): void
    {
        DispatchBlackoutSlotRecord::query()->whereKey($slot->id()->toString())->delete();
    }

    public function findByKey(SlotDate $date, TimeSlot $slot, ?string $municipalityId): ?DispatchBlackoutSlot
    {
        $query = DispatchBlackoutSlotRecord::query()
            ->where('slot_date', $date->toString())
            ->where('time_slot', $slot->toString());

        $municipalityId === null
            ? $query->whereNull('municipality_id')
            : $query->where('municipality_id', $municipalityId);

        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function isBlocked(SlotDate $date, TimeSlot $slot, ?string $municipalityId): bool
    {
        return DispatchBlackoutSlotRecord::query()
            ->where('slot_date', $date->toString())
            ->where('time_slot', $slot->toString())
            ->where(function (Builder $query) use ($municipalityId): void {
                // Siempre cuenta el bloqueo global; y, si se pidió un municipio, también el suyo.
                $query->whereNull('municipality_id');
                if ($municipalityId !== null) {
                    $query->orWhere('municipality_id', $municipalityId);
                }
            })
            ->exists();
    }

    /**
     * @return list<DispatchBlackoutSlot>
     */
    public function listForDate(SlotDate $date, ?string $municipalityId): array
    {
        $query = DispatchBlackoutSlotRecord::query()
            ->where('slot_date', $date->toString());

        $municipalityId === null
            ? $query->whereNull('municipality_id')
            : $query->where(function (Builder $inner) use ($municipalityId): void {
                // Incluye la franja global Y la del municipio pedido.
                $inner->whereNull('municipality_id')->orWhere('municipality_id', $municipalityId);
            });

        return $query
            ->orderBy('time_slot')
            ->orderBy('municipality_id')
            ->get()
            ->map(fn (DispatchBlackoutSlotRecord $record): DispatchBlackoutSlot => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(DispatchBlackoutSlotRecord $record): DispatchBlackoutSlot
    {
        return DispatchBlackoutSlot::fromState(
            DispatchBlackoutSlotId::fromString($record->id),
            // La columna es date; se recorta por si el driver devuelve 'Y-m-d H:i:s'.
            SlotDate::fromString(substr($record->slot_date, 0, 10)),
            TimeSlot::fromString($record->time_slot),
            $record->municipality_id,
            $record->reason,
            $record->created_by,
        );
    }
}
