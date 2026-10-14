<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Event\MaintenanceCancelled;
use Logistics\FleetOps\Domain\Event\MaintenanceCompleted;
use Logistics\FleetOps\Domain\Event\MaintenanceScheduled;
use Logistics\FleetOps\Domain\Event\MaintenanceStarted;
use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\Model\MaintenanceOrder;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceOrderId;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceStatus;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceType;
use Logistics\FleetOps\Domain\ValueObject\Money;
use Logistics\FleetOps\Domain\ValueObject\Odometer;

function agendarOrden(): MaintenanceOrder
{
    return MaintenanceOrder::schedule(
        MaintenanceOrderId::generate(),
        'vehicle-1',
        MaintenanceType::Preventive,
        Odometer::km(100_000),
        'Taller Central',
        new DateTimeImmutable('2026-10-01T08:00:00+00:00'),
        null,
    );
}

/**
 * @return list<MaintenanceItem>
 */
function itemsDeOrden(): array
{
    return [
        MaintenanceItem::of('Cambio de aceite', 2, Money::of(5_000), Money::of(3_000)), // 16000
        MaintenanceItem::of('Filtro', 1, Money::of(1_200), Money::of(800)),             // 2000
    ];
}

it('agenda una orden en estado Scheduled sin ítems ni costo', function (): void {
    $order = agendarOrden();

    expect($order->status())->toBe(MaintenanceStatus::Scheduled)
        ->and($order->items())->toBe([])
        ->and($order->totalCost()->cents)->toBe(0);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenanceScheduled::class);
});

it('rechaza agendar sin vehículo', function (): void {
    expect(fn () => MaintenanceOrder::schedule(
        MaintenanceOrderId::generate(),
        '   ',
        MaintenanceType::Corrective,
        null,
        null,
        new DateTimeImmutable('2026-10-01T08:00:00+00:00'),
        null,
    ))->toThrow(FleetOpsException::class, 'requiere un vehículo');
});

it('recorre schedule → start → complete y suma los costos de los ítems', function (): void {
    $order = agendarOrden();
    $order->releaseEvents();

    $order->start(new DateTimeImmutable('2026-10-02T09:00:00+00:00'));
    expect($order->status())->toBe(MaintenanceStatus::InProgress);

    $order->complete(itemsDeOrden(), new DateTimeImmutable('2026-10-02T15:00:00+00:00'));

    // 16000 + 2000 = 18000.
    expect($order->status())->toBe(MaintenanceStatus::Completed)
        ->and($order->totalCost()->cents)->toBe(18_000)
        ->and($order->items())->toHaveCount(2)
        ->and($order->completedAt()?->format('Y-m-d'))->toBe('2026-10-02');

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(2)
        ->and($events[0])->toBeInstanceOf(MaintenanceStarted::class)
        ->and($events[1])->toBeInstanceOf(MaintenanceCompleted::class);
});

it('permite completar directo desde Scheduled', function (): void {
    $order = agendarOrden();
    $order->complete(itemsDeOrden(), new DateTimeImmutable('2026-10-02T15:00:00+00:00'));

    expect($order->status())->toBe(MaintenanceStatus::Completed)
        ->and($order->totalCost()->cents)->toBe(18_000);
});

it('solo inicia una orden agendada', function (): void {
    $order = agendarOrden();
    $order->cancel(new DateTimeImmutable('2026-10-02T09:00:00+00:00'));

    expect(fn () => $order->start(new DateTimeImmutable('2026-10-03T09:00:00+00:00')))
        ->toThrow(FleetOpsException::class, 'orden agendada');
});

it('no completa una orden cancelada', function (): void {
    $order = agendarOrden();
    $order->cancel(new DateTimeImmutable('2026-10-02T09:00:00+00:00'));

    expect(fn () => $order->complete([], new DateTimeImmutable('2026-10-03T09:00:00+00:00')))
        ->toThrow(FleetOpsException::class, 'No se puede completar');
});

it('no cancela una orden ya completada', function (): void {
    $order = agendarOrden();
    $order->complete(itemsDeOrden(), new DateTimeImmutable('2026-10-02T15:00:00+00:00'));

    expect(fn () => $order->cancel(new DateTimeImmutable('2026-10-03T09:00:00+00:00')))
        ->toThrow(FleetOpsException::class, 'ya completada');
});

it('cancela una orden agendada y graba el evento', function (): void {
    $order = agendarOrden();
    $order->releaseEvents();

    $order->cancel(new DateTimeImmutable('2026-10-02T09:00:00+00:00'));
    expect($order->status())->toBe(MaintenanceStatus::Cancelled);

    $events = $order->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(MaintenanceCancelled::class);

    // Cancelar de nuevo es idempotente: no graba otro evento.
    $order->cancel(new DateTimeImmutable('2026-10-03T09:00:00+00:00'));
    expect($order->releaseEvents())->toBe([]);
});
