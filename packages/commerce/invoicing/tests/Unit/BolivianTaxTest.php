<?php

declare(strict_types=1);

use Commerce\Invoicing\Domain\Exception\InvoicingException;
use Commerce\Invoicing\Domain\ValueObject\BolivianTax;

it('descompone el IVA por dentro con el caso exacto 113000 → neto 100000 / iva 13000', function (): void {
    $breakdown = BolivianTax::fromGross(113000);

    expect($breakdown['net'])->toBe(100000)
        ->and($breakdown['iva'])->toBe(13000);
});

it('garantiza siempre que neto + iva === bruto', function (int $gross): void {
    $breakdown = BolivianTax::fromGross($gross);

    expect($breakdown['net'] + $breakdown['iva'])->toBe($gross);
})->with([0, 1, 99, 100000, 100001, 250075, 999999]);

it('redondea el neto medio hacia arriba y saca el iva por diferencia', function (): void {
    // 100000 / 1,13 = 88495,57… → neto 88496; iva = 100000 − 88496 = 11504.
    $breakdown = BolivianTax::fromGross(100000);

    expect($breakdown['net'])->toBe(88496)
        ->and($breakdown['iva'])->toBe(11504);
});

it('expone el value object con neto e iva', function (): void {
    $tax = BolivianTax::of(226000);

    expect($tax->netCents)->toBe(200000)
        ->and($tax->ivaCents)->toBe(26000);
});

it('rechaza un bruto negativo', function (): void {
    BolivianTax::fromGross(-1);
})->toThrow(InvoicingException::class);
