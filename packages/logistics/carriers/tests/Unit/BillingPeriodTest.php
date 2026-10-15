<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\BillingPeriod;

it('acepta un período bien formado YYYY-MM y lo normaliza', function (): void {
    $period = BillingPeriod::of(' 2026-10 ');

    expect($period->year)->toBe(2026)
        ->and($period->month)->toBe(10)
        ->and($period->toString())->toBe('2026-10');
});

it('rechaza períodos mal formados', function (string $value): void {
    expect(fn () => BillingPeriod::of($value))
        ->toThrow(CarriersException::class, 'Período de facturación inválido');
})->with(['2026-13', '2026-00', '2026/10', '26-10', '2026-1', 'octubre']);

it('compara períodos por igualdad', function (): void {
    expect(BillingPeriod::of('2026-10')->equals(BillingPeriod::of('2026-10')))->toBeTrue()
        ->and(BillingPeriod::of('2026-10')->equals(BillingPeriod::of('2026-11')))->toBeFalse();
});
