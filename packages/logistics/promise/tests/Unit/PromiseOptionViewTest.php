<?php

declare(strict_types=1);

use Logistics\Promise\Application\ReadModel\PromiseOptionView;
use Logistics\Promise\Domain\ValueObject\PromiseOption;

/*
| ReadModel PromiseOptionView (forma plana en primitivos de una opción de promesa). Prueban la
| proyección desde el VO de dominio y el toArray() que viaja a la API de canales.
*/

it('proyecta una opción de dominio a su forma de lectura', function (): void {
    // 2026-10-19 es lunes (ISO 1).
    $view = PromiseOptionView::fromOption(PromiseOption::of(new DateTimeImmutable('2026-10-19'), 'AM', 4.0));

    expect($view->slotDate)->toBe('2026-10-19')
        ->and($view->weekday)->toBe(1)
        ->and($view->timeSlot)->toBe('AM')
        ->and($view->available)->toBe(4.0);
});

it('serializa a un arreglo plano de primitivos', function (): void {
    $view = PromiseOptionView::fromOption(PromiseOption::of(new DateTimeImmutable('2026-10-19'), '09:00-13:00', 2.5));

    expect($view->toArray())->toBe([
        'slot_date' => '2026-10-19',
        'weekday' => 1,
        'time_slot' => '09:00-13:00',
        'available' => 2.5,
    ]);
});
