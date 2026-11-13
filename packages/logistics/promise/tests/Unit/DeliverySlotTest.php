<?php

declare(strict_types=1);

use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;

/*
| VO DeliverySlot (franja de entrega: día + etiqueta de franja). Prueban la normalización a medianoche,
| la validación de la etiqueta (vacía, muy larga, formato) y la igualdad/forma canónica.
*/

it('normaliza la fecha a medianoche y la etiqueta a mayúsculas', function (): void {
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-18 15:45:30'), ' am ');

    expect($slot->date->format('Y-m-d H:i:s'))->toBe('2026-10-18 00:00:00')
        ->and($slot->timeSlot)->toBe('AM')
        ->and($slot->dateString())->toBe('2026-10-18');
});

it('rechaza una franja vacía', function (): void {
    expect(fn () => DeliverySlot::of(new DateTimeImmutable('2026-10-18'), '   '))
        ->toThrow(PromiseException::class, 'no puede estar vacía');
});

it('rechaza una franja que excede el largo máximo', function (): void {
    expect(fn () => DeliverySlot::of(new DateTimeImmutable('2026-10-18'), str_repeat('A', 21)))
        ->toThrow(PromiseException::class, 'excede');
});

it('rechaza una franja con caracteres fuera del alfabeto permitido', function (): void {
    expect(fn () => DeliverySlot::of(new DateTimeImmutable('2026-10-18'), 'ma!ñana'))
        ->toThrow(PromiseException::class, 'formato inválido');
});

it('acepta una etiqueta de rango HH:MM-HH:MM', function (): void {
    $slot = DeliverySlot::of(new DateTimeImmutable('2026-10-18'), '09:00-13:00');

    expect($slot->timeSlot)->toBe('09:00-13:00')
        ->and($slot->toCanonical())->toBe('2026-10-18|09:00-13:00');
});

it('dos slots del mismo día y franja son iguales sin importar la hora de origen', function (): void {
    $a = DeliverySlot::of(new DateTimeImmutable('2026-10-18 08:00:00'), 'PM');
    $b = DeliverySlot::of(new DateTimeImmutable('2026-10-18 20:00:00'), 'PM');
    $c = DeliverySlot::of(new DateTimeImmutable('2026-10-19 08:00:00'), 'PM');
    $d = DeliverySlot::of(new DateTimeImmutable('2026-10-18 08:00:00'), 'AM');

    expect($a->equals($b))->toBeTrue()
        ->and($a->equals($c))->toBeFalse()
        ->and($a->equals($d))->toBeFalse();
});
