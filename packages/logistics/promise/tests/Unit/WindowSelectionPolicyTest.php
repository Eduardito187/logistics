<?php

declare(strict_types=1);

use Logistics\Promise\Domain\ValueObject\WindowSelectionPolicy;

/*
| VO WindowSelectionPolicy (pesos de la elección automática de ventana). Prueban los defaults, el
| clamp a 0 de pesos negativos en `of` y la construcción tolerante desde config con `fromArray`.
*/

it('los pesos por defecto priorizan la preferencia del cliente', function (): void {
    $policy = WindowSelectionPolicy::default();

    expect($policy->preferenceWeight)->toBe(0.6)
        ->and($policy->balanceWeight)->toBe(0.3)
        ->and($policy->earlyWeight)->toBe(0.1);
});

it('of recorta a cero cualquier peso negativo', function (): void {
    $policy = WindowSelectionPolicy::of(-1.0, 0.5, -0.2);

    expect($policy->preferenceWeight)->toBe(0.0)
        ->and($policy->balanceWeight)->toBe(0.5)
        ->and($policy->earlyWeight)->toBe(0.0);
});

it('fromArray reconstruye los pesos desde primitivos de configuración', function (): void {
    $policy = WindowSelectionPolicy::fromArray([
        'preference' => '0.7',
        'balance' => 0.2,
        'early' => 0.1,
    ]);

    expect($policy->preferenceWeight)->toBe(0.7)
        ->and($policy->balanceWeight)->toBe(0.2)
        ->and($policy->earlyWeight)->toBe(0.1);
});

it('fromArray cae al default cuando un peso falta o no es numérico o es negativo', function (): void {
    $policy = WindowSelectionPolicy::fromArray([
        'balance' => 'nope',
        'early' => -3,
    ]);

    expect($policy->preferenceWeight)->toBe(0.6)
        ->and($policy->balanceWeight)->toBe(0.3)
        ->and($policy->earlyWeight)->toBe(0.1);
});

it('fromArray con un arreglo vacío devuelve los defaults', function (): void {
    $policy = WindowSelectionPolicy::fromArray([]);

    expect($policy->preferenceWeight)->toBe(0.6)
        ->and($policy->balanceWeight)->toBe(0.3)
        ->and($policy->earlyWeight)->toBe(0.1);
});
