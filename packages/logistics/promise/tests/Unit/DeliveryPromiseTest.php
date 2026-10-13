<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Event\PromiseConfirmed;
use Logistics\Promise\Domain\Event\PromiseExpired;
use Logistics\Promise\Domain\Event\PromiseReserved;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\PromiseStatus;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;

/**
 * Cotiza una promesa de ejemplo en estado Quoted.
 */
function quotePromise(): DeliveryPromise
{
    return DeliveryPromise::quote(
        PromiseId::generate(),
        'SHIP-260012345',
        '0191c2a0-1111-7000-8000-000000000001',
        DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'AM'),
    );
}

it('quotes a promise in Quoted status without recording events', function (): void {
    $promise = quotePromise();

    expect($promise->status())->toBe(PromiseStatus::Quoted)
        ->and($promise->reservedUntil())->toBeNull()
        ->and($promise->releaseEvents())->toBe([]);
});

it('reserves a quoted promise, moving to Reserved and recording PromiseReserved', function (): void {
    $promise = quotePromise();
    $window = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));

    $promise->reserve($window);

    $events = $promise->releaseEvents();
    expect($promise->status())->toBe(PromiseStatus::Reserved)
        ->and($promise->reservedUntil())->toBe($window)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromiseReserved::class);

    /** @var PromiseReserved $event */
    $event = $events[0];
    expect($event->eventName())->toBe('promise.promise_reserved')
        ->and($event->shipmentRef)->toBe('SHIP-260012345')
        ->and($event->timeSlot)->toBe('AM')
        ->and($event->payload()['slot_date'])->toBe('2026-10-20');
});

it('confirms a reserved promise within the window and records PromiseConfirmed', function (): void {
    $promise = quotePromise();
    $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00')));
    $promise->releaseEvents();

    $promise->confirm(new DateTimeImmutable('2026-10-18 11:59:59'));

    $events = $promise->releaseEvents();
    expect($promise->status())->toBe(PromiseStatus::Confirmed)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromiseConfirmed::class)
        ->and($events[0]->eventName())->toBe('promise.promise_confirmed');
});

it('walks the happy path quote to reserve to confirm', function (): void {
    $promise = quotePromise();
    $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00')));
    $promise->confirm(new DateTimeImmutable('2026-10-18 10:00:00'));

    expect($promise->status())->toBe(PromiseStatus::Confirmed);
});

it('refuses to confirm when the reservation window has expired', function (): void {
    $promise = quotePromise();
    $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00')));

    expect(fn () => $promise->confirm(new DateTimeImmutable('2026-10-18 12:00:01')))
        ->toThrow(PromiseException::class, 'venció');
});

it('refuses to confirm a promise that was never reserved', function (): void {
    $promise = quotePromise();

    expect(fn () => $promise->confirm(new DateTimeImmutable('2026-10-18 10:00:00')))
        ->toThrow(PromiseException::class, 'reservada');
});

it('expires a reserved promise, moving to Expired and recording PromiseExpired', function (): void {
    $promise = quotePromise();
    $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00')));
    $promise->releaseEvents();

    $promise->expire();

    $events = $promise->releaseEvents();
    expect($promise->status())->toBe(PromiseStatus::Expired)
        ->and($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(PromiseExpired::class)
        ->and($events[0]->eventName())->toBe('promise.promise_expired');
});

it('refuses to expire a promise that is not reserved', function (): void {
    $promise = quotePromise();

    expect(fn () => $promise->expire())
        ->toThrow(PromiseException::class, 'reservada');
});

it('refuses to reserve a promise that is not quoted', function (): void {
    $promise = quotePromise();
    $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00')));

    expect(fn () => $promise->reserve(ReservationWindow::until(new DateTimeImmutable('2026-10-19 12:00:00'))))
        ->toThrow(PromiseException::class, 'cotizada');
});

it('reconstitutes without recording events', function (): void {
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-20'), 'PM');
    $window = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));

    $promise = DeliveryPromise::reconstitute(
        PromiseId::generate(),
        'SHIP-1',
        '0191c2a0-1111-7000-8000-000000000001',
        $slot,
        PromiseStatus::Reserved,
        $window,
    );

    expect($promise->releaseEvents())->toBe([])
        ->and($promise->status())->toBe(PromiseStatus::Reserved)
        ->and($promise->reservedUntil())->toBe($window);
});
