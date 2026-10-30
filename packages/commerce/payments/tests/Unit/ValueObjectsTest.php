<?php

declare(strict_types=1);

use Commerce\Payments\Domain\Exception\PaymentsException;
use Commerce\Payments\Domain\ValueObject\Money;
use Commerce\Payments\Domain\ValueObject\PaymentResult;

it('Money por defecto es BOB y suma exacto en centavos', function (): void {
    $a = Money::of(1500);
    expect($a->currency)->toBe('BOB')
        ->and($a->cents)->toBe(1500);

    $b = Money::of(500, 'BOB');
    expect($a->add($b)->cents)->toBe(2000);
});

it('Money rechaza montos negativos y operar monedas distintas', function (): void {
    expect(fn () => Money::of(-1))->toThrow(PaymentsException::class);
    expect(fn () => Money::of(100, 'BOB')->add(Money::of(100, 'USD')))->toThrow(PaymentsException::class);
});

it('Money resta de forma segura y falla si quedaría negativo', function (): void {
    expect(Money::of(1000)->subtract(Money::of(400))->cents)->toBe(600);
    expect(fn () => Money::of(100)->subtract(Money::of(200)))->toThrow(PaymentsException::class);
});

it('PaymentResult aprobado exige referencia y rechazado lleva motivo', function (): void {
    $ok = PaymentResult::approved('GW-123');
    expect($ok->approved)->toBeTrue()
        ->and($ok->gatewayRef)->toBe('GW-123')
        ->and($ok->failureReason)->toBeNull();

    $ko = PaymentResult::declined('insufficient_funds');
    expect($ko->approved)->toBeFalse()
        ->and($ko->gatewayRef)->toBeNull()
        ->and($ko->failureReason)->toBe('insufficient_funds');

    expect(fn () => PaymentResult::approved('   '))->toThrow(PaymentsException::class);
});
