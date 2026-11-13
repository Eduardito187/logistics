<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\CustomerTimePreference;

/*
| VO de preferencia horaria del cliente (Módulo 1). Prueban que puntúa (0..1) una ventana según la
| preferencia, que es tolerante a datos pobres (cae a "sin preferencia") y que valida las horas.
*/

it('sin preferencia puntúa neutro alto todas las ventanas', function (): void {
    $preference = CustomerTimePreference::none();

    expect($preference->score(9 * 60, 13 * 60))->toBe(1.0)
        ->and($preference->score(null, null))->toBe(1.0);
});

it('una ventana sin bordes horarios recibe un puntaje neutro que no sesga', function (): void {
    expect(CustomerTimePreference::morning()->score(null, null))->toBe(0.5);
});

it('mañana prefiere la ventana de la mañana sobre la de la tarde', function (): void {
    $morning = CustomerTimePreference::morning();

    expect($morning->score(8 * 60, 12 * 60))->toBe(1.0)
        ->and($morning->score(14 * 60, 18 * 60))->toBe(0.0);
});

it('after(14:00) puntúa por la fracción de la ventana que empieza a esa hora o después', function (): void {
    $after = CustomerTimePreference::after('14:00');

    // Ventana 12:00-16:00: solo 14:00-16:00 (la mitad) cae dentro del rango deseado.
    expect($after->score(12 * 60, 16 * 60))->toBe(0.5)
        ->and($after->score(14 * 60, 18 * 60))->toBe(1.0)
        ->and($after->score(8 * 60, 12 * 60))->toBe(0.0);
});

it('between(09:00, 13:00) puntúa por el solape con el rango', function (): void {
    $between = CustomerTimePreference::between('09:00', '13:00');

    expect($between->score(9 * 60, 13 * 60))->toBe(1.0)
        ->and($between->score(11 * 60, 15 * 60))->toBe(0.5);
});

it('fromArray es tolerante: forma ausente o desconocida cae a sin preferencia', function (): void {
    expect(CustomerTimePreference::fromArray(null)->kind)->toBe(CustomerTimePreference::KIND_NONE)
        ->and(CustomerTimePreference::fromArray(['kind' => 'garbage'])->kind)->toBe(CustomerTimePreference::KIND_NONE)
        ->and(CustomerTimePreference::fromArray(['kind' => 'after'])->kind)->toBe(CustomerTimePreference::KIND_NONE)
        ->and(CustomerTimePreference::fromArray(['kind' => 'morning'])->kind)->toBe(CustomerTimePreference::KIND_MORNING);
});

it('fromArray reconstruye after/before/between con sus bordes', function (): void {
    $after = CustomerTimePreference::fromArray(['kind' => 'after', 'from' => '14:00']);

    expect($after->kind)->toBe(CustomerTimePreference::KIND_AFTER)
        ->and($after->fromMinute)->toBe(14 * 60)
        ->and($after->toArray())->toBe(['kind' => 'after', 'from' => '14:00', 'to' => '24:00']);
});

it('rechaza una hora mal formada o fuera de rango', function (): void {
    expect(fn () => CustomerTimePreference::after('25:00'))->toThrow(PromiseException::class);
    expect(fn () => CustomerTimePreference::after('nope'))->toThrow(PromiseException::class);
    expect(fn () => CustomerTimePreference::between('13:00', '09:00'))->toThrow(PromiseException::class);
});
