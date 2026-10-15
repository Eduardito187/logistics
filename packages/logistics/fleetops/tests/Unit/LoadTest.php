<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\Load;

it('arma una carga con descripción, peso e ítems', function (): void {
    $load = Load::of('Línea blanca', 1250.5, [['name' => 'Refrigeradora', 'qty' => 3]]);

    expect($load->description)->toBe('Línea blanca')
        ->and($load->weightKg)->toBe(1250.5)
        ->and($load->items)->toBe([['name' => 'Refrigeradora', 'qty' => 3]]);
});

it('normaliza una descripción vacía a nulo', function (): void {
    expect(Load::of('   ')->description)->toBeNull();
});

it('rechaza peso negativo', function (): void {
    expect(fn () => Load::of('x', -5.0))->toThrow(FleetOpsException::class, 'peso de la carga no puede ser negativo');
});

it('construye una carga vacía', function (): void {
    $load = Load::empty();

    expect($load->description)->toBeNull()
        ->and($load->weightKg)->toBeNull()
        ->and($load->items)->toBe([]);
});

it('va y vuelve de su forma primitiva', function (): void {
    $load = Load::fromArray(['description' => 'Mixto', 'weight_kg' => 800, 'items' => [['name' => 'TV', 'qty' => 2]]]);

    expect($load->weightKg)->toBe(800.0)
        ->and($load->toArray())->toBe([
            'description' => 'Mixto',
            'weight_kg' => 800.0,
            'items' => [['name' => 'TV', 'qty' => 2]],
        ]);
});
