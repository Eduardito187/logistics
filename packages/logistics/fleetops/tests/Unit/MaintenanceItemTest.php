<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Exception\FleetOpsException;
use Logistics\FleetOps\Domain\ValueObject\MaintenanceItem;
use Logistics\FleetOps\Domain\ValueObject\Money;

it('calcula el total de línea como (repuesto + mano de obra) × cantidad', function (): void {
    $item = MaintenanceItem::of('Cambio de aceite', 2, Money::of(5_000), Money::of(3_000));

    // (5000 + 3000) × 2 = 16000.
    expect($item->lineTotal()->cents)->toBe(16_000)
        ->and($item->lineTotal()->currency)->toBe('BOB');
});

it('rechaza un ítem sin descripción', function (): void {
    expect(fn () => MaintenanceItem::of('  ', 1, Money::of(100), Money::of(100)))
        ->toThrow(FleetOpsException::class, 'requiere una descripción');
});

it('rechaza cantidad menor o igual a cero', function (): void {
    expect(fn () => MaintenanceItem::of('x', 0, Money::of(100), Money::of(100)))
        ->toThrow(FleetOpsException::class, 'mayor que cero');
});

it('rechaza repuesto y mano de obra en distinta moneda', function (): void {
    expect(fn () => MaintenanceItem::of('x', 1, Money::of(100, 'BOB'), Money::of(100, 'USD')))
        ->toThrow(FleetOpsException::class, 'misma moneda');
});

it('va y vuelve de su forma primitiva usando la moneda de la orden', function (): void {
    $data = ['description' => 'Frenos', 'qty' => 4, 'part_cost_cents' => 1_200, 'labor_cost_cents' => 800];
    $item = MaintenanceItem::fromArray($data, 'BOB');

    expect($item->toArray())->toBe($data)
        // (1200 + 800) × 4 = 8000.
        ->and($item->lineTotal()->cents)->toBe(8_000);
});
