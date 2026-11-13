<?php

declare(strict_types=1);

use Logistics\Promise\Domain\ValueObject\PromiseOption;

/*
| VO PromiseOption (opción de promesa calculada: fecha, franja y cupo). Prueban la normalización de la
| fecha a medianoche, la fecha ISO y el día de la semana ISO-8601.
*/

it('normaliza la fecha a medianoche', function (): void {
    $option = PromiseOption::of(new DateTimeImmutable('2026-10-18 17:30:00'), 'AM', 5.0);

    expect($option->date->format('Y-m-d H:i:s'))->toBe('2026-10-18 00:00:00')
        ->and($option->timeSlot)->toBe('AM')
        ->and($option->available)->toBe(5.0)
        ->and($option->dateString())->toBe('2026-10-18');
});

it('calcula el día de la semana ISO-8601 (lunes = 1 … domingo = 7)', function (): void {
    // 2026-10-18 es domingo, 2026-10-19 es lunes.
    expect(PromiseOption::of(new DateTimeImmutable('2026-10-18'), 'AM', 1.0)->weekday())->toBe(7)
        ->and(PromiseOption::of(new DateTimeImmutable('2026-10-19'), 'AM', 1.0)->weekday())->toBe(1);
});
