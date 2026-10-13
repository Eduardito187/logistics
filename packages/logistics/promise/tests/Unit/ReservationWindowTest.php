<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;

it('builds a window from an explicit expiration instant', function (): void {
    $expiresAt = new DateTimeImmutable('2026-10-18 12:00:00');

    $window = ReservationWindow::until($expiresAt);

    expect($window->expiresAt)->toEqual($expiresAt);
});

it('builds a window as now plus minutes', function (): void {
    $now = new DateTimeImmutable('2026-10-18 12:00:00');

    $window = ReservationWindow::minutesFrom($now, 30);

    expect($window->expiresAt->format('Y-m-d H:i:s'))->toBe('2026-10-18 12:30:00');
});

it('rejects a non-positive duration', function (int $minutes): void {
    expect(fn () => ReservationWindow::minutesFrom(new DateTimeImmutable('2026-10-18 12:00:00'), $minutes))
        ->toThrow(PromiseException::class, 'al menos un minuto');
})->with([
    'zero' => [0],
    'negative' => [-5],
]);

it('is not expired before the expiration instant', function (): void {
    $window = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));

    expect($window->isExpiredAt(new DateTimeImmutable('2026-10-18 11:59:59')))->toBeFalse();
});

it('is expired at and after the expiration instant', function (): void {
    $window = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));

    expect($window->isExpiredAt(new DateTimeImmutable('2026-10-18 12:00:00')))->toBeTrue()
        ->and($window->isExpiredAt(new DateTimeImmutable('2026-10-18 12:00:01')))->toBeTrue();
});

it('compares two windows by expiration instant', function (): void {
    $a = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));
    $b = ReservationWindow::until(new DateTimeImmutable('2026-10-18 12:00:00'));
    $c = ReservationWindow::until(new DateTimeImmutable('2026-10-18 13:00:00'));

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse();
});
