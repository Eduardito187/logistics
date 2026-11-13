<?php

declare(strict_types=1);

use Logistics\Compensation\Application\ReadModel\VoucherValidityView;
use Logistics\Compensation\Domain\ValueObject\Money;

// Proyección de validez para el POS: canjeable, motivo de rechazo o inexistente; montos siempre legibles.

it('arma la vista de un vale canjeable', function (): void {
    $view = VoucherValidityView::valid(Money::of(3_000), '2026-12-31T23:59:59+00:00');

    expect($view->valid)->toBeTrue()
        ->and($view->amountCents)->toBe(3_000)
        ->and($view->amountFormatted)->toBe('Bs 30,00')
        ->and($view->status)->toBe('issued')
        ->and($view->reasonCode)->toBeNull()
        ->and($view->expiresAt)->toBe('2026-12-31T23:59:59+00:00');
});

it('arma la vista de un vale inválido con motivo', function (): void {
    $view = VoucherValidityView::invalid('voucher_redeemed', 'redeemed', Money::of(3_000));

    expect($view->valid)->toBeFalse()
        ->and($view->reasonCode)->toBe('voucher_redeemed')
        ->and($view->status)->toBe('redeemed')
        ->and($view->expiresAt)->toBeNull();
});

it('arma la vista de un código inexistente sin revelar información', function (): void {
    $view = VoucherValidityView::notFound();

    expect($view->valid)->toBeFalse()
        ->and($view->amountCents)->toBe(0)
        ->and($view->status)->toBe('not_found')
        ->and($view->reasonCode)->toBe('voucher_not_found')
        ->and($view->amountFormatted)->toBe('Bs 0,00');
});

it('expone su forma primitiva con claves del contrato', function (): void {
    $view = VoucherValidityView::valid(Money::of(3_000), '2026-12-31T23:59:59+00:00');

    expect($view->toArray())->toBe([
        'valid' => true,
        'amount_cents' => 3_000,
        'currency' => 'BOB',
        'amount_formatted' => 'Bs 30,00',
        'expires_at' => '2026-12-31T23:59:59+00:00',
        'status' => 'issued',
        'reason' => null,
    ]);
});
