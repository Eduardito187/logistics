<?php

declare(strict_types=1);

use Logistics\FleetOps\Domain\Service\ProfitabilityCalculator;

/**
 * Servicio de dominio PURO de rentabilidad (ROADMAP 3-06): margen = ingreso − costo. El ingreso es
 * opcional; sin ingreso el margen es null (no se inventa ingreso). El margen puede ser negativo (pérdida).
 */
it('computes margin when revenue is provided', function (): void {
    $calc = new ProfitabilityCalculator;

    // 50 000 ingreso − 39 600 costo = 10 400 de margen.
    expect($calc->marginCents(50_000, 39_600))->toBe(10_400);
});

it('returns a negative margin when cost exceeds revenue (loss)', function (): void {
    $calc = new ProfitabilityCalculator;

    // 30 000 ingreso − 39 600 costo = −9 600 (pérdida): Money no lo permitiría, el margen sí.
    expect($calc->marginCents(30_000, 39_600))->toBe(-9_600);
});

it('returns null margin when revenue is not provided', function (): void {
    $calc = new ProfitabilityCalculator;

    expect($calc->marginCents(null, 39_600))->toBeNull()
        ->and($calc->marginRatio(null, 39_600))->toBeNull();
});

it('computes the margin ratio over revenue', function (): void {
    $calc = new ProfitabilityCalculator;

    // (50 000 − 40 000) / 50 000 = 0.2.
    expect($calc->marginRatio(50_000, 40_000))->toBe(0.2);
});

it('returns null ratio when revenue is zero (no division by zero)', function (): void {
    $calc = new ProfitabilityCalculator;

    expect($calc->marginRatio(0, 39_600))->toBeNull();
});
