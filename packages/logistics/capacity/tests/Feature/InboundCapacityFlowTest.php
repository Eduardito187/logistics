<?php

declare(strict_types=1);

use Logistics\Capacity\Application\Command\ConsumeCapacity\ConsumeCapacityCommand;
use Logistics\Capacity\Application\Command\DefineCapacity\DefineCapacityCommand;
use Logistics\Capacity\Application\Command\ReleaseCapacity\ReleaseCapacityCommand;
use Logistics\Capacity\Application\Query\GetReceivingDockCalendar\GetReceivingDockCalendarQuery;
use Logistics\Capacity\Application\ReadModel\ReceivingDockCapacityView;
use Logistics\Capacity\Domain\Exception\CapacityException;
use Logistics\Capacity\Infrastructure\Persistence\Eloquent\CapacityBucketRecord;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;

/**
 * Espejo de recepción (inbound) de punta a punta contra PostgreSQL (base logistics_test): el contexto
 * inbound reutiliza DefineCapacity/ConsumeCapacity/ReleaseCapacity con un scope de andén para reservar y
 * liberar un cupo de recepción, y GetReceivingDockCalendar lo lee por almacén/día. Skip sin PostgreSQL.
 */
beforeEach(function (): void {})->skip(
    env('DB_CONNECTION') !== 'pgsql',
    'Requiere PostgreSQL (base logistics_test). Correr dentro de los containers.',
);

const INBOUND_WAREHOUSE = '0192aaaa-0000-7000-8000-00000000d001';
const INBOUND_DOCK = '0192aaaa-0000-7000-8000-00000000d002';

it('reserves and releases a receiving-dock slot through the shared capacity engine', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    // El contexto inbound define el cupo de andén (almacén + andén + día + franja), en citas.
    $commands->dispatch(new DefineCapacityCommand(
        nodeId: null,
        zoneId: null,
        date: '2026-11-02',
        timeSlot: '08:00-12:00',
        unit: 'appointments',
        total: 3.0,
        municipalityId: null,
        warehouseId: INBOUND_WAREHOUSE,
        dockId: INBOUND_DOCK,
    ));

    // Agendar un ASN consume una cita del andén.
    $commands->dispatch(new ConsumeCapacityCommand(
        nodeId: null,
        zoneId: null,
        date: '2026-11-02',
        timeSlot: '08:00-12:00',
        unit: 'appointments',
        amount: 2.0,
        municipalityId: null,
        warehouseId: INBOUND_WAREHOUSE,
        dockId: INBOUND_DOCK,
    ));

    $record = CapacityBucketRecord::query()
        ->where('scope_kind', 'inbound')
        ->where('warehouse_id', INBOUND_WAREHOUSE)
        ->firstOrFail();

    expect($record->scope_kind)->toBe('inbound')
        ->and($record->dock_id)->toBe(INBOUND_DOCK)
        ->and((float) $record->used)->toBe(2.0);

    // Cancelar el ASN libera la cita.
    $commands->dispatch(new ReleaseCapacityCommand(
        nodeId: null,
        zoneId: null,
        date: '2026-11-02',
        timeSlot: '08:00-12:00',
        unit: 'appointments',
        amount: 1.0,
        municipalityId: null,
        warehouseId: INBOUND_WAREHOUSE,
        dockId: INBOUND_DOCK,
    ));

    expect((float) $record->fresh()->used)->toBe(1.0);
});

it('never oversells a receiving-dock slot', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);

    $commands->dispatch(new DefineCapacityCommand(
        nodeId: null,
        zoneId: null,
        date: '2026-11-03',
        timeSlot: '08:00-12:00',
        unit: 'appointments',
        total: 1.0,
        municipalityId: null,
        warehouseId: INBOUND_WAREHOUSE,
        dockId: INBOUND_DOCK,
    ));

    expect(fn () => $commands->dispatch(new ConsumeCapacityCommand(
        nodeId: null,
        zoneId: null,
        date: '2026-11-03',
        timeSlot: '08:00-12:00',
        unit: 'appointments',
        amount: 2.0,
        municipalityId: null,
        warehouseId: INBOUND_WAREHOUSE,
        dockId: INBOUND_DOCK,
    )))->toThrow(CapacityException::class);
});

it('reads the receiving-dock calendar for a warehouse and day', function (): void {
    /** @var CommandBus $commands */
    $commands = app(CommandBus::class);
    /** @var QueryBus $queries */
    $queries = app(QueryBus::class);

    foreach (['08:00-12:00', '12:00-16:00'] as $slot) {
        $commands->dispatch(new DefineCapacityCommand(
            nodeId: null,
            zoneId: null,
            date: '2026-11-04',
            timeSlot: $slot,
            unit: 'appointments',
            total: 4.0,
            municipalityId: null,
            warehouseId: INBOUND_WAREHOUSE,
            dockId: INBOUND_DOCK,
        ));
    }

    /** @var list<ReceivingDockCapacityView> $calendar */
    $calendar = $queries->ask(new GetReceivingDockCalendarQuery(INBOUND_WAREHOUSE, '2026-11-04'));

    expect($calendar)->toHaveCount(2)
        ->and($calendar[0]->warehouseId)->toBe(INBOUND_WAREHOUSE)
        ->and($calendar[0]->dockId)->toBe(INBOUND_DOCK)
        ->and($calendar[0]->unit)->toBe('appointments')
        ->and($calendar[0]->available)->toBe(4.0)
        ->and($calendar[0]->toArray())->toHaveKeys(['id', 'warehouse_id', 'dock_id', 'date', 'time_slot', 'unit', 'total', 'used', 'available', 'full']);
});
