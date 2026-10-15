<?php

declare(strict_types=1);

namespace Logistics\Capacity\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Capacity\Domain\Contract\DispatchBlackoutRepository;
use Logistics\Capacity\Domain\DispatchBlackoutDay;
use Logistics\Capacity\Domain\ValueObject\DispatchBlackoutDayId;
use Logistics\Capacity\Domain\ValueObject\SlotDate;

/**
 * Implementación Eloquent del repositorio de días bloqueados de despacho. Mapea el agregado
 * DispatchBlackoutDay hacia y desde la fila dispatch_blackout_days.
 */
final class EloquentDispatchBlackoutRepository implements DispatchBlackoutRepository
{
    public function nextIdentity(): DispatchBlackoutDayId
    {
        return DispatchBlackoutDayId::generate();
    }

    public function save(DispatchBlackoutDay $day): void
    {
        $id = $day->id()->toString();

        $record = DispatchBlackoutDayRecord::query()->find($id) ?? new DispatchBlackoutDayRecord;
        $record->id = $id;
        $record->blackout_date = $day->date()->toString();
        $record->municipality_id = $day->municipalityId();
        $record->reason = $day->reason();
        $record->created_by = $day->createdBy();
        $record->save();
    }

    public function remove(DispatchBlackoutDay $day): void
    {
        DispatchBlackoutDayRecord::query()->whereKey($day->id()->toString())->delete();
    }

    public function findByDate(SlotDate $date, ?string $municipalityId): ?DispatchBlackoutDay
    {
        $query = DispatchBlackoutDayRecord::query()->where('blackout_date', $date->toString());

        $municipalityId === null
            ? $query->whereNull('municipality_id')
            : $query->where('municipality_id', $municipalityId);

        $record = $query->first();

        return $record === null ? null : $this->toDomain($record);
    }

    public function isBlocked(SlotDate $date, ?string $municipalityId): bool
    {
        return DispatchBlackoutDayRecord::query()
            ->where('blackout_date', $date->toString())
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
     * @return list<DispatchBlackoutDay>
     */
    public function listInRange(SlotDate $from, SlotDate $to, ?string $municipalityId = null): array
    {
        $query = DispatchBlackoutDayRecord::query()
            ->whereBetween('blackout_date', [$from->toString(), $to->toString()]);

        if ($municipalityId !== null) {
            // Incluye el municipio pedido Y los bloqueos globales que lo afectan.
            $query->where(function (Builder $inner) use ($municipalityId): void {
                $inner->whereNull('municipality_id')->orWhere('municipality_id', $municipalityId);
            });
        }

        return $query
            ->orderBy('blackout_date')
            ->orderBy('municipality_id')
            ->get()
            ->map(fn (DispatchBlackoutDayRecord $record): DispatchBlackoutDay => $this->toDomain($record))
            ->values()
            ->all();
    }

    private function toDomain(DispatchBlackoutDayRecord $record): DispatchBlackoutDay
    {
        return DispatchBlackoutDay::fromState(
            DispatchBlackoutDayId::fromString($record->id),
            // La columna es date; se recorta por si el driver devuelve 'Y-m-d H:i:s'.
            SlotDate::fromString(substr($record->blackout_date, 0, 10)),
            $record->municipality_id,
            $record->reason,
            $record->created_by,
        );
    }
}
