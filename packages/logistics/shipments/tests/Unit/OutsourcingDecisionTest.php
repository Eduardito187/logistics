<?php

declare(strict_types=1);

use Logistics\Shipments\Application\Service\OutsourcingDecision;

/**
 * Política de tercerización por departamento destino: un departamento mapeado va por un tercero (con su
 * transportadora y almacén de handoff); cualquier otro —incluido un destino nulo o desconocido— cae a flota
 * propia, que es el caso por defecto. La comparación ignora la caja del código de departamento.
 */
it('outsources a mapped destination department with its carrier and hub', function (): void {
    $decision = new OutsourcingDecision([
        'santa_cruz' => ['carrier_id' => 'c1', 'carrier_hub_node_id' => 'hub1'],
    ]);

    $choice = $decision->decide('santa_cruz');

    expect($choice->isOutsourced())->toBeTrue()
        ->and($choice->mode)->toBe('outsourced')
        ->and($choice->carrierId)->toBe('c1')
        ->and($choice->carrierHubNodeId)->toBe('hub1');
});

it('matches the destination department ignoring letter case and surrounding spaces', function (): void {
    $decision = new OutsourcingDecision([
        'tarija' => ['carrier_id' => 'c9', 'carrier_hub_node_id' => null],
    ]);

    $choice = $decision->decide('  Tarija  ');

    expect($choice->isOutsourced())->toBeTrue()
        ->and($choice->carrierId)->toBe('c9')
        ->and($choice->carrierHubNodeId)->toBeNull();
});

it('falls back to own fleet for an unmapped department', function (): void {
    $decision = new OutsourcingDecision([
        'santa_cruz' => ['carrier_id' => 'c1', 'carrier_hub_node_id' => null],
    ]);

    $choice = $decision->decide('la_paz');

    expect($choice->isOutsourced())->toBeFalse()
        ->and($choice->mode)->toBe('own_fleet')
        ->and($choice->carrierId)->toBeNull()
        ->and($choice->carrierHubNodeId)->toBeNull();
});

it('falls back to own fleet when the destination department is null or empty', function (): void {
    $decision = new OutsourcingDecision([
        'santa_cruz' => ['carrier_id' => 'c1', 'carrier_hub_node_id' => null],
    ]);

    expect($decision->decide(null)->mode)->toBe('own_fleet')
        ->and($decision->decide('')->mode)->toBe('own_fleet');
});

it('falls back to own fleet when the map is empty (default: everything own fleet)', function (): void {
    $decision = new OutsourcingDecision([]);

    expect($decision->decide('santa_cruz')->isOutsourced())->toBeFalse();
});
