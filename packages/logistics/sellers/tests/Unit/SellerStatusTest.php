<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\ValueObject\SellerStatus;

/**
 * Cobertura del enum SellerStatus del contexto sellers: valores estables y etiqueta en inglés para el
 * admin (regla 5 de CLAUDE.md). PHP puro, sin Laravel.
 */
it('expone los valores estables de cada estado', function (): void {
    expect(SellerStatus::Onboarding->value)->toBe('onboarding')
        ->and(SellerStatus::Active->value)->toBe('active')
        ->and(SellerStatus::Suspended->value)->toBe('suspended')
        ->and(SellerStatus::cases())->toHaveCount(3);
});

it('se reconstituye desde su valor primitivo', function (): void {
    expect(SellerStatus::from('onboarding'))->toBe(SellerStatus::Onboarding)
        ->and(SellerStatus::from('active'))->toBe(SellerStatus::Active)
        ->and(SellerStatus::from('suspended'))->toBe(SellerStatus::Suspended);
});

it('da una etiqueta en inglés para cada estado', function (SellerStatus $status, string $label): void {
    expect($status->label())->toBe($label);
})->with([
    'en alta' => [SellerStatus::Onboarding, 'Onboarding'],
    'activo' => [SellerStatus::Active, 'Active'],
    'suspendido' => [SellerStatus::Suspended, 'Suspended'],
]);
