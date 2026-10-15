<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\MaintenanceOrderRecord;

/**
 * Traduce entre el agregado MaintenanceOrder (dominio) y su fila Eloquent. Único punto que conoce ambos.
 *
 * Los ítems se guardan como jsonb sin moneda (la aporta la columna `currency` de la orden); al
 * reconstituir se recombinan con esa moneda. Las columnas datetime vienen como Carbon (cast del Record):
 * se pasan a DateTimeImmutable para el dominio puro.
 */
final class MaintenanceOrderMapper
{
    public function toDomain(MaintenanceOrderRecord $record): MaintenanceOrder
    {
        $items = [];
        foreach ($record->items as $item) {
            $items[] = MaintenanceItem::fromArray($item, $record->currency);
        }

        return MaintenanceOrder::reconstitute(
            MaintenanceOrderId::fromString($record->id),
            $record->vehicle_id,
            MaintenanceType::from($record->type),
            MaintenanceStatus::from($record->status),
            $record->odometer_km !== null ? Odometer::km($record->odometer_km) : null,
            $record->workshop,
            $items,
            Money::of($record->total_cost_cents, $record->currency),
            $record->scheduled_at?->toDateTimeImmutable(),
            $record->started_at?->toDateTimeImmutable(),
            $record->completed_at?->toDateTimeImmutable(),
            $record->note,
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, type: string, status: string, odometer_km: int|null, workshop: string|null, items: list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}>, total_cost_cents: int, currency: string, scheduled_at: DateTimeImmutable|null, started_at: DateTimeImmutable|null, completed_at: DateTimeImmutable|null, note: string|null}
     */
    public function toRow(MaintenanceOrder $order): array
    {
        $items = array_map(
            static fn (MaintenanceItem $item): array => $item->toArray(),
            $order->items(),
        );

        return [
            'id' => $order->id()->toString(),
            'vehicle_id' => $order->vehicleId(),
            'type' => $order->type()->value,
            'status' => $order->status()->value,
            'odometer_km' => $order->odometer()?->km,
            'workshop' => $order->workshop(),
            'items' => $items,
            'total_cost_cents' => $order->totalCost()->cents,
            'currency' => $order->totalCost()->currency,
            'scheduled_at' => $order->scheduledAt(),
            'started_at' => $order->startedAt(),
            'completed_at' => $order->completedAt(),
            'note' => $order->note(),
        ];
    }
}
