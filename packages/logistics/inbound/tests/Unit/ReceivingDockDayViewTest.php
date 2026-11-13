<?php

declare(strict_types=1);

use Logistics\Inbound\Application\ReadModel\ReceivingDockDayView;

it('suma las citas de todas las franjas del día y conserva las franjas', function (): void {
    $view = ReceivingDockDayView::of('2026-10-20', [
        ['time_slot' => '08:00-12:00', 'appointments' => 2],
        ['time_slot' => '14:00-18:00', 'appointments' => 3],
    ]);

    expect($view->date)->toBe('2026-10-20')
        ->and($view->appointments)->toBe(5)
        ->and($view->slots)->toHaveCount(2);
});

it('serializa a primitivos con el total y las franjas', function (): void {
    $view = ReceivingDockDayView::of('2026-10-21', [
        ['time_slot' => '09:00-10:00', 'appointments' => 1],
    ]);

    expect($view->toArray())->toBe([
        'date' => '2026-10-21',
        'appointments' => 1,
        'slots' => [
            ['time_slot' => '09:00-10:00', 'appointments' => 1],
        ],
    ]);
});

it('un día sin citas tiene total cero y sin franjas', function (): void {
    $view = ReceivingDockDayView::of('2026-10-22', []);

    expect($view->appointments)->toBe(0)
        ->and($view->slots)->toBe([]);
});
