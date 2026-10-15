<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Event\NodeReceptionOpened;
use Logistics\Execution\Domain\Event\NodeReceptionReconciled;
use Logistics\Execution\Domain\Event\NodeUnitReceived;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\NodeReception;
use Logistics\Execution\Domain\ValueObject\Corridor;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\NodeReceptionId;
use Logistics\Execution\Domain\ValueObject\NodeReceptionStatus;

/**
 * @param list<string> $expected
 */
function openReception(array $expected = ['U-1', 'U-2', 'U-3']): NodeReception
{
    return NodeReception::open(
        NodeReceptionId::generate(),
        'd1111111-1111-7111-8111-111111111111',
        'n1111111-1111-7111-8111-111111111111',
        'n2222222-2222-7222-8222-222222222222',
        Corridor::CdToStore,
        $expected,
        new DateTimeImmutable('2026-10-13T08:00:00+00:00'),
    );
}

function receive(NodeReception $reception, string $unitId, string $key): void
{
    $reception->receiveUnit(
        $unitId,
        GeoPoint::of(-16.5, -68.15),
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T09:00:00+00:00'),
        $key,
    );
}

it('opens a reception and records NodeReceptionOpened with the expected count', function (): void {
    $reception = openReception();

    expect($reception->status())->toBe(NodeReceptionStatus::Open)
        ->and($reception->corridor())->toBe(Corridor::CdToStore)
        ->and($reception->expectedUnitIds())->toBe(['U-1', 'U-2', 'U-3']);

    $events = $reception->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeReceptionOpened::class);

    /** @var NodeReceptionOpened $opened */
    $opened = $events[0];
    expect($opened->expectedCount)->toBe(3)
        ->and($opened->corridor)->toBe('CD_TO_STORE');
});

it('normalizes expected units: trims, drops empties and deduplicates', function (): void {
    $reception = openReception([' U-1 ', 'U-1', '', 'U-2']);

    expect($reception->expectedUnitIds())->toBe(['U-1', 'U-2']);
});

it('rejects opening with the same origin and destination node', function (): void {
    expect(fn () => NodeReception::open(
        NodeReceptionId::generate(),
        'd1111111-1111-7111-8111-111111111111',
        'n1111111-1111-7111-8111-111111111111',
        'n1111111-1111-7111-8111-111111111111',
        Corridor::CdToCd,
        ['U-1'],
        new DateTimeImmutable('2026-10-13T08:00:00+00:00'),
    ))->toThrow(ExecutionException::class, 'origen y el de destino');
});

it('records each received unit as an immutable scan with its event', function (): void {
    $reception = openReception();
    $reception->releaseEvents();

    receive($reception, 'U-1', 'k-1');

    expect($reception->receivedUnitIds())->toBe(['U-1']);
    $events = $reception->releaseEvents();
    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(NodeUnitReceived::class);
});

it('is idempotent per idempotency key: a resend does not duplicate the unit nor the event', function (): void {
    $reception = openReception();
    $reception->releaseEvents();

    receive($reception, 'U-1', 'k-1');
    $reception->releaseEvents();
    receive($reception, 'U-1', 'k-1');

    expect($reception->receivedUnits())->toHaveCount(1)
        ->and($reception->releaseEvents())->toBe([]);
});

it('reconciles computing missing (expected not received)', function (): void {
    $reception = openReception(['U-1', 'U-2', 'U-3']);
    receive($reception, 'U-1', 'k-1');
    receive($reception, 'U-2', 'k-2');
    $reception->releaseEvents();

    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );

    expect($reception->status())->toBe(NodeReceptionStatus::Reconciled)
        ->and($reception->missingUnitIds())->toBe(['U-3'])
        ->and($reception->surplusUnitIds())->toBe([]);

    $events = $reception->releaseEvents();
    /** @var NodeReceptionReconciled $reconciled */
    $reconciled = $events[0];
    expect($reconciled)->toBeInstanceOf(NodeReceptionReconciled::class)
        ->and($reconciled->missingUnitIds)->toBe(['U-3'])
        ->and($reconciled->surplusUnitIds)->toBe([])
        ->and($reconciled->expectedCount)->toBe(3)
        ->and($reconciled->receivedCount)->toBe(2)
        ->and($reconciled->complete)->toBeFalse();
});

it('reconciles computing surplus (received not expected)', function (): void {
    $reception = openReception(['U-1']);
    receive($reception, 'U-1', 'k-1');
    receive($reception, 'U-9', 'k-9');
    $reception->releaseEvents();

    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );

    expect($reception->missingUnitIds())->toBe([])
        ->and($reception->surplusUnitIds())->toBe(['U-9']);
});

it('marks the reconciliation complete when there are no discrepancies', function (): void {
    $reception = openReception(['U-1', 'U-2']);
    receive($reception, 'U-1', 'k-1');
    receive($reception, 'U-2', 'k-2');
    $reception->releaseEvents();

    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );

    $events = $reception->releaseEvents();
    /** @var NodeReceptionReconciled $reconciled */
    $reconciled = $events[0];
    expect($reconciled->complete)->toBeTrue();
});

it('rejects receiving a unit after the reception is reconciled', function (): void {
    $reception = openReception();
    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );
    $reception->releaseEvents();

    expect(fn () => receive($reception, 'U-1', 'k-1'))
        ->toThrow(ExecutionException::class, 'ya conciliada');
});

it('is idempotent on reconcile: a second reconcile records no new event', function (): void {
    $reception = openReception(['U-1']);
    receive($reception, 'U-1', 'k-1');
    $reception->releaseEvents();

    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T10:00:00+00:00'),
    );
    $reception->releaseEvents();

    $reception->reconcile(
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T11:00:00+00:00'),
    );

    expect($reception->releaseEvents())->toBe([]);
});
