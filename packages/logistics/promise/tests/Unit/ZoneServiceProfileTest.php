<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\ServiceWindow;
use Logistics\Promise\Domain\ValueObject\ZoneServiceProfile;

/*
| VO ZoneServiceProfile (perfil de servicio de una zona: días, tiempo base y ventanas). Prueban la
| normalización de días (únicos y ordenados), las ramas de validación y la cobertura de franja contra
| las ventanas horarias.
*/

it('normaliza los días de servicio: únicos y ordenados', function (): void {
    $profile = ZoneServiceProfile::of([5, 1, 1, 3], 60, []);

    expect($profile->serviceDays)->toBe([1, 3, 5])
        ->and($profile->leadMinutes)->toBe(60)
        ->and($profile->hasServiceDays())->toBeTrue();
});

it('una zona sin días de servicio no atiende ningún día', function (): void {
    $profile = ZoneServiceProfile::of([], 0, []);

    expect($profile->hasServiceDays())->toBeFalse()
        ->and($profile->servesWeekday(1))->toBeFalse();
});

it('servesWeekday responde según los días declarados', function (): void {
    $profile = ZoneServiceProfile::of([1, 3, 5], 0, []);

    expect($profile->servesWeekday(3))->toBeTrue()
        ->and($profile->servesWeekday(2))->toBeFalse();
});

it('rechaza un tiempo base negativo', function (): void {
    expect(fn () => ZoneServiceProfile::of([1], -1, []))
        ->toThrow(PromiseException::class, 'no puede ser negativo');
});

it('rechaza un día de servicio fuera de rango', function (int $day): void {
    expect(fn () => ZoneServiceProfile::of([$day], 0, []))
        ->toThrow(PromiseException::class, 'fuera de rango');
})->with([
    'cero' => [0],
    'ocho' => [8],
]);

it('sin ventanas declaradas toda franja pasa', function (): void {
    $profile = ZoneServiceProfile::of([1], 0, []);

    expect($profile->coversSlot('09:00-13:00'))->toBeTrue()
        ->and($profile->coversSlot('AM'))->toBeTrue();
});

it('una etiqueta no-rango pasa aunque haya ventanas', function (): void {
    $profile = ZoneServiceProfile::of([1], 0, [ServiceWindow::of(9 * 60, 13 * 60)]);

    expect($profile->coversSlot('AM'))->toBeTrue();
});

it('una franja de rango se contrasta contra las ventanas de atención', function (): void {
    $profile = ZoneServiceProfile::of([1], 0, [ServiceWindow::of(9 * 60, 13 * 60)]);

    expect($profile->coversSlot('12:00-14:00'))->toBeTrue()
        ->and($profile->coversSlot('14:00-18:00'))->toBeFalse();
});

it('un rango invertido o degenerado no se interpreta y por lo tanto pasa', function (): void {
    $profile = ZoneServiceProfile::of([1], 0, [ServiceWindow::of(9 * 60, 13 * 60)]);

    // El patrón casa, pero inicio >= fin: parseRange devuelve null y la franja pasa.
    expect($profile->coversSlot('14:00-14:00'))->toBeTrue();
});
