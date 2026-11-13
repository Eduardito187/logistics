<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\ValueObject\BirthDate;

/**
 * Fecha de nacimiento: comparación por día y cálculo de edad contra una fecha de referencia.
 */
it('normaliza la hora al inicio del día', function (): void {
    $birthDate = BirthDate::of(new DateTimeImmutable('1990-05-10 15:45:30'));

    expect($birthDate->toString())->toBe('1990-05-10')
        ->and((string) $birthDate)->toBe('1990-05-10')
        ->and($birthDate->date->format('H:i:s'))->toBe('00:00:00');
});

it('calcula la edad en años cumplidos a la fecha de referencia', function (string $ref, int $expected): void {
    $birthDate = BirthDate::of(new DateTimeImmutable('1990-05-10'));

    expect($birthDate->ageAt(new DateTimeImmutable($ref)))->toBe($expected);
})->with([
    'justo antes del cumpleaños' => ['2020-05-09', 29],
    'el día del cumpleaños' => ['2020-05-10', 30],
    'después del cumpleaños' => ['2020-05-11', 30],
]);

it('devuelve 0 cuando la fecha de nacimiento es futura respecto a la referencia', function (): void {
    $birthDate = BirthDate::of(new DateTimeImmutable('2030-01-01'));

    expect($birthDate->ageAt(new DateTimeImmutable('2026-01-01')))->toBe(0);
});
