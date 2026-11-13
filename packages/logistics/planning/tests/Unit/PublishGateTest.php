<?php

declare(strict_types=1);

use Logistics\Planning\Domain\Service\PublishGate;
use Logistics\Planning\Domain\ValueObject\PlanStatus;
use Logistics\Planning\Domain\ValueObject\PublishThresholds;

/*
| PublishGate (Fase 3.2): puerta de publicación automática, dominio puro. Decide si un plan propuesto puede
| publicarse solo o si se retiene con motivos. Determinista; no toca nada externo.
*/

function pgThresholds(float $max = 1.0, float $min = 0.0, bool $requireAvailable = true): PublishThresholds
{
    return PublishThresholds::of($max, $min, $requireAvailable);
}

it('publica un plan propuesto, con paradas, vehículo disponible y ocupación dentro del máximo', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        4,
        0.8,
        0.6,
        true,
        pgThresholds(),
    );

    expect($decision->publishable)->toBeTrue()
        ->and($decision->reasons)->toBe([]);
});

it('retiene un plan que no está propuesto', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Draft,
        4,
        0.5,
        0.5,
        true,
        pgThresholds(),
    );

    expect($decision->publishable)->toBeFalse()
        ->and($decision->reasons)->not->toBe([]);
});

it('retiene un plan sin paradas', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        0,
        null,
        null,
        true,
        pgThresholds(),
    );

    expect($decision->publishable)->toBeFalse();
});

it('retiene si el vehículo ya no está disponible y la regla lo exige', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        3,
        0.5,
        0.5,
        false,
        pgThresholds(requireAvailable: true),
    );

    expect($decision->publishable)->toBeFalse();
});

it('no retiene por disponibilidad si la regla está desactivada', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        3,
        0.5,
        0.5,
        false,
        pgThresholds(requireAvailable: false),
    );

    expect($decision->publishable)->toBeTrue();
});

it('retiene un camión sobrecargado (ocupación por encima del máximo)', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        3,
        1.2,
        0.4,
        true,
        pgThresholds(max: 1.0),
    );

    expect($decision->publishable)->toBeFalse();
});

it('no verifica ocupación cuando no se conoce la capacidad (ratios null)', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Proposed,
        3,
        null,
        null,
        true,
        pgThresholds(max: 1.0),
    );

    expect($decision->publishable)->toBeTrue();
});

it('acumula todos los motivos del retención', function (): void {
    $decision = (new PublishGate)->evaluate(
        PlanStatus::Draft,
        0,
        1.5,
        1.5,
        false,
        pgThresholds(max: 1.0, requireAvailable: true),
    );

    expect($decision->publishable)->toBeFalse()
        ->and(count($decision->reasons))->toBeGreaterThanOrEqual(3);
});
