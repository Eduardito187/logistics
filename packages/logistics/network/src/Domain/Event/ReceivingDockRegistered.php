<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró un andén de recepción en un almacén central. Lo consumen capacity (para preparar los
 * cupos de recepción del andén) y cualquier integración aguas abajo, vía outbox.
 */
final readonly class ReceivingDockRegistered extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        private string $warehouseId,
        private string $code,
        private string $name,
        private int $appointmentsPerSlot,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'network.receiving_dock_registered';
    }

    /**
     * @return array{warehouse_id: string, code: string, name: string, appointments_per_slot: int}
     */
    public function payload(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'code' => $this->code,
            'name' => $this->name,
            'appointments_per_slot' => $this->appointmentsPerSlot,
        ];
    }
}
