<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Resumen del barrido de mantenimiento preventivo: hay vehículos por vencer o vencidos. Se emite un
 * único evento al outbox; el relay lo entrega a notificaciones para avisar al supervisor (principio 4:
 * nada de I/O externo síncrono en el barrido).
 */
final readonly class FleetMaintenanceDueAlerted extends BaseDomainEvent
{
    /**
     * @param list<array{vehicle_id: string, service_kind: string, next_due_km: int|null, next_due_at: string|null, current_odometer_km: int|null, overdue: bool}> $items
     */
    public function __construct(
        string $aggregateId,
        public string $asOf,
        public int $overdueCount,
        public int $dueSoonCount,
        public array $items,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'fleetops.maintenance_due_alerted';
    }

    public function payload(): array
    {
        return [
            'as_of' => $this->asOf,
            'overdue_count' => $this->overdueCount,
            'due_soon_count' => $this->dueSoonCount,
            'items' => $this->items,
        ];
    }
}
