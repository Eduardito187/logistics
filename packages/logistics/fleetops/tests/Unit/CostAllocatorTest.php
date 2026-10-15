<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Service\CostAllocator;

/**
 * Servicio de dominio PURO del modelo de costos (ROADMAP 3-01): costo por km, atribución a una ruta y
 * costo por entrega, con casos de datos conocidos. Sin Laravel ni base de datos.
 */
it('computes cost per km from total cost and kilometers', function (): void {
    $allocator = new CostAllocator;

    // 39 600 centavos / 120 km = 330 centavos/km.
    expect($allocator->costPerKmCents(39_600, 120))->toBe(330);
});

it('returns null cost per km when there are no kilometers', function (): void {
    $allocator = new CostAllocator;

    expect($allocator->costPerKmCents(39_600, 0))->toBeNull()
        ->and($allocator->costPerKmCents(39_600, -5))->toBeNull();
});

it('attributes a route cost from cost per km and route kilometers', function (): void {
    $allocator = new CostAllocator;

    // 330 centavos/km × 40 km = 13 200 centavos.
    expect($allocator->routeCostCents(330, 40))->toBe(13_200);
});

it('attributes zero route cost when cost per km is unknown or distance is not positive', function (): void {
    $allocator = new CostAllocator;

    expect($allocator->routeCostCents(null, 40))->toBe(0)
        ->and($allocator->routeCostCents(330, 0))->toBe(0);
});

it('allocates a total cost proportionally to a share', function (): void {
    $allocator = new CostAllocator;

    // 10 000 centavos repartidos según 30 de 120 unidades = 2 500 centavos.
    expect($allocator->allocateByShare(10_000, 120, 30))->toBe(2_500)
        ->and($allocator->allocateByShare(10_000, 0, 30))->toBe(0)
        ->and($allocator->allocateByShare(10_000, 120, 0))->toBe(0);
});

it('computes cost per delivery and returns null without deliveries', function (): void {
    $allocator = new CostAllocator;

    // 13 200 centavos / 4 entregas = 3 300 centavos.
    expect($allocator->costPerDeliveryCents(13_200, 4))->toBe(3_300)
        ->and($allocator->costPerDeliveryCents(13_200, 0))->toBeNull();
});
