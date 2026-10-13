<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;

/**
 * Proyección de lectura de una orden de mantenimiento para listados y API. Primitivos: no expone objetos
 * de dominio. El dinero va como centavos + moneda; las fechas en ISO 8601 (o null).
 */
final readonly class MaintenanceOrderView
{
    /**
     * @param list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}> $items
     */
    private function __construct(
        public string $id,
        public string $vehicleId,
        public string $type,
        public string $status,
        public ?int $odometerKm,
        public ?string $workshop,
        public array $items,
        public int $totalCostCents,
        public string $currency,
        public ?string $scheduledAt,
        public ?string $startedAt,
        public ?string $completedAt,
        public ?string $note,
    ) {}

    public static function fromDomain(MaintenanceOrder $order): self
    {
        $items = array_map(
            static fn (MaintenanceItem $item): array => $item->toArray(),
            $order->items(),
        );

        return new self(
            $order->id()->toString(),
            $order->vehicleId(),
            $order->type()->value,
            $order->status()->value,
            $order->odometer()?->km,
            $order->workshop(),
            $items,
            $order->totalCost()->cents,
            $order->totalCost()->currency,
            $order->scheduledAt()?->format(DATE_ATOM),
            $order->startedAt()?->format(DATE_ATOM),
            $order->completedAt()?->format(DATE_ATOM),
            $order->note(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, type: string, status: string, odometer_km: int|null, workshop: string|null, items: list<array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}>, total_cost_cents: int, currency: string, scheduled_at: string|null, started_at: string|null, completed_at: string|null, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'type' => $this->type,
            'status' => $this->status,
            'odometer_km' => $this->odometerKm,
            'workshop' => $this->workshop,
            'items' => $this->items,
            'total_cost_cents' => $this->totalCostCents,
            'currency' => $this->currency,
            'scheduled_at' => $this->scheduledAt,
            'started_at' => $this->startedAt,
            'completed_at' => $this->completedAt,
            'note' => $this->note,
        ];
    }
}
