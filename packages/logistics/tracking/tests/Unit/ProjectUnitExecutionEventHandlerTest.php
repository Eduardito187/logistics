<?php

declare(strict_types=1);

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Outbox\Domain\Contract\OutboxStore;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEvent;
use Logistics\Tracking\Application\Command\ProjectUnitExecutionEvent\ProjectUnitExecutionEventHandler;
use Logistics\Tracking\Domain\Contract\UnitTrackingRepository;
use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;
use Logistics\Tracking\Domain\ValueObject\UnitTrackingId;

/**
 * Repositorio de seguimiento per-bulto en memoria: upsert por id, búsqueda por unit_id y por envío.
 */
function inMemoryUnitTrackingRepository(): UnitTrackingRepository
{
    return new class implements UnitTrackingRepository
    {
        /** @var array<string, UnitTracking> */
        public array $units = [];

        public function nextIdentity(): UnitTrackingId
        {
            return UnitTrackingId::generate();
        }

        public function save(UnitTracking $unit): void
        {
            $this->units[$unit->id()->toString()] = $unit;
        }

        public function findByUnitId(string $unitId): ?UnitTracking
        {
            foreach ($this->units as $unit) {
                if ($unit->unitId() === $unitId) {
                    return $unit;
                }
            }

            return null;
        }

        public function listByShipmentId(string $shipmentId): array
        {
            return array_values(array_filter(
                $this->units,
                static fn (UnitTracking $unit): bool => $unit->shipmentId() === $shipmentId,
            ));
        }
    };
}

function recordDomainEventsForUnits(): RecordDomainEvents
{
    $store = new class implements OutboxStore
    {
        /** @var list<OutboxMessage> */
        public array $messages = [];

        public function append(OutboxMessage $message): void
        {
            $this->messages[] = $message;
        }

        public function pullBatch(int $limit, DateTimeImmutable $now): array
        {
            return [];
        }

        public function save(OutboxMessage $message): void {}
    };

    $clock = new class implements Clock
    {
        public function now(): DateTimeImmutable
        {
            return new DateTimeImmutable('2026-10-13T12:00:00+00:00');
        }
    };

    return new RecordDomainEvents($store, $clock);
}

it('auto-opens the unit the first time it is seen and projects its status', function (): void {
    $units = inMemoryUnitTrackingRepository();
    $handler = new ProjectUnitExecutionEventHandler($units, recordDomainEventsForUnits());

    $handler->handle(new ProjectUnitExecutionEvent(
        'execution.shipment_loaded',
        'ship-1',
        'unit-1',
        '2026-10-13T11:00:00+00:00',
    ));

    $unit = $units->findByUnitId('unit-1');
    expect($unit)->not->toBeNull()
        ->and($unit?->status())->toBe(UnitStatus::Loaded)
        ->and($unit?->shipmentId())->toBe('ship-1');
});

it('ignores an event type that does not map to a unit status', function (): void {
    $units = inMemoryUnitTrackingRepository();
    $handler = new ProjectUnitExecutionEventHandler($units, recordDomainEventsForUnits());

    $handler->handle(new ProjectUnitExecutionEvent(
        'execution.vehicle_incident_reported',
        'ship-1',
        'unit-1',
        '2026-10-13T11:00:00+00:00',
    ));

    expect($units->findByUnitId('unit-1'))->toBeNull();
});

it('cascades a shipment terminal event to all known units (no unit_id)', function (): void {
    $units = inMemoryUnitTrackingRepository();

    // Dos bultos ya conocidos del envío, en camino.
    foreach (['unit-1', 'unit-2'] as $unitId) {
        $unit = UnitTracking::open(UnitTrackingId::generate(), 'ship-1', $unitId);
        $unit->applyStatus(UnitStatus::Loaded, null, new DateTimeImmutable('2026-10-13T10:00:00+00:00'));
        $units->save($unit);
    }

    $handler = new ProjectUnitExecutionEventHandler($units, recordDomainEventsForUnits());

    $handler->handle(new ProjectUnitExecutionEvent(
        'execution.shipment_delivered',
        'ship-1',
        '', // sin unit_id: desenlace del envío
        '2026-10-13T12:00:00+00:00',
    ));

    expect($units->findByUnitId('unit-1')?->status())->toBe(UnitStatus::Delivered)
        ->and($units->findByUnitId('unit-2')?->status())->toBe(UnitStatus::Delivered);
});

it('is monotonic: an out-of-order delivered-then-loaded does not move a terminal unit', function (): void {
    $units = inMemoryUnitTrackingRepository();
    $handler = new ProjectUnitExecutionEventHandler($units, recordDomainEventsForUnits());

    $handler->handle(new ProjectUnitExecutionEvent('execution.shipment_loaded', 'ship-1', 'unit-1', '2026-10-13T10:00:00+00:00'));
    $handler->handle(new ProjectUnitExecutionEvent('execution.shipment_delivered', 'ship-1', 'unit-1', '2026-10-13T12:00:00+00:00'));

    // Reenvío tardío de "cargado": el bulto ya está entregado (terminal), no retrocede.
    $handler->handle(new ProjectUnitExecutionEvent('execution.shipment_loaded', 'ship-1', 'unit-1', '2026-10-13T11:00:00+00:00'));

    expect($units->findByUnitId('unit-1')?->status())->toBe(UnitStatus::Delivered);
});
