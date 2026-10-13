<?php

declare(strict_types=1);

use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\ScanEvent;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Execution\Domain\ValueObject\ScanEventId;
use Logistics\Execution\Domain\ValueObject\ScanType;

function scanEvent(string $unitId = 'u-1', string $idempotencyKey = 'k-1'): ScanEvent
{
    return ScanEvent::record(
        ScanEventId::generate(),
        'd1111111-1111-7111-8111-111111111111',
        ScanType::Preparation,
        $unitId,
        GeoPoint::of(-16.5, -68.15),
        DeviceContext::of('device-01', 'user-07'),
        new DateTimeImmutable('2026-10-13T08:00:00+00:00'),
        $idempotencyKey,
    );
}

it('records an immutable scan with all its evidence', function (): void {
    $scan = scanEvent();

    expect($scan->type)->toBe(ScanType::Preparation)
        ->and($scan->unitId)->toBe('u-1')
        ->and($scan->idempotencyKey)->toBe('k-1')
        ->and($scan->geo->lat)->toBe(-16.5)
        ->and($scan->device->userId)->toBe('user-07')
        ->and($scan->occurredAt->format(DATE_ATOM))->toBe('2026-10-13T08:00:00+00:00');
});

it('rejects an empty unit id', function (): void {
    expect(fn () => scanEvent(unitId: '  '))
        ->toThrow(ExecutionException::class, 'unitId');
});

it('rejects an empty idempotency key', function (): void {
    expect(fn () => scanEvent(idempotencyKey: ''))
        ->toThrow(ExecutionException::class, 'idempotencia');
});

it('rejects a geo point out of range', function (): void {
    expect(fn () => GeoPoint::of(120.0, 0.0))
        ->toThrow(ExecutionException::class, 'latitud fuera de rango');
});

it('rejects an empty device id', function (): void {
    expect(fn () => DeviceContext::of('', 'user-07'))
        ->toThrow(ExecutionException::class, 'dispositivo');
});
