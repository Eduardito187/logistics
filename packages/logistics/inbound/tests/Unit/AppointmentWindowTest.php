<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AppointmentWindow;

it('reconstruye la ventana desde fecha + franja canónica', function (): void {
    $window = AppointmentWindow::fromPrimitives('2026-10-20', '08:00-12:00');

    expect($window->date)->toBe('2026-10-20')
        ->and($window->startTime)->toBe('08:00')
        ->and($window->endTime)->toBe('12:00')
        ->and($window->timeSlot())->toBe('08:00-12:00');
});

it('rechaza una franja que no tiene dos partes HH:MM-HH:MM', function (): void {
    expect(fn () => AppointmentWindow::fromPrimitives('2026-10-20', '0800'))
        ->toThrow(InboundException::class);
});

it('compara dos ventanas por fecha y horas', function (): void {
    $window = AppointmentWindow::of('2026-10-20', '08:00', '12:00');

    expect($window->equals(AppointmentWindow::of('2026-10-20', '08:00', '12:00')))->toBeTrue()
        ->and($window->equals(AppointmentWindow::of('2026-10-21', '08:00', '12:00')))->toBeFalse()
        ->and($window->equals(AppointmentWindow::of('2026-10-20', '09:00', '12:00')))->toBeFalse()
        ->and($window->equals(AppointmentWindow::of('2026-10-20', '08:00', '13:00')))->toBeFalse();
});

it('serializa la ventana a primitivos con la franja canónica', function (): void {
    expect(AppointmentWindow::of('2026-10-20', '08:00', '12:00')->toArray())->toBe([
        'date' => '2026-10-20',
        'start_time' => '08:00',
        'end_time' => '12:00',
        'time_slot' => '08:00-12:00',
    ]);
});
