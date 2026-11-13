<?php

declare(strict_types=1);

use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\VoucherCode;

// Código DT-XXXX-XXXX con dígito de control, generado con CSPRNG.

it('genera un código con el formato del contrato', function (): void {
    $code = VoucherCode::generate();

    expect($code->value)->toMatch('/^DT-[0-9A-Z]{4}-[0-9A-Z]{4}$/');
});

it('un código generado se reconstruye desde su cadena', function (): void {
    $code = VoucherCode::generate();

    expect(VoucherCode::fromString($code->value)->value)->toBe($code->value);
});

it('normaliza a mayúsculas al reconstruir', function (): void {
    $code = VoucherCode::generate();
    $lower = strtolower($code->value);

    expect(VoucherCode::fromString($lower)->value)->toBe($code->value);
});

it('rechaza un formato inválido', function (): void {
    expect(fn () => VoucherCode::fromString('XX-1234-5678'))->toThrow(CompensationException::class);
});

it('rechaza un dígito de control inválido', function (): void {
    // DT-0000-0000 tiene formato pero no corresponde a un check-char válido (y 0 no está en el alfabeto).
    expect(fn () => VoucherCode::fromString('DT-2345-6789'))->toThrow(CompensationException::class);
});

it('enmascara todo salvo el último bloque', function (): void {
    $code = VoucherCode::generate();

    expect($code->masked())->toBe('DT-••••-'.substr($code->value, -4));
});

it('compara dos códigos por valor', function (): void {
    $code = VoucherCode::generate();

    expect($code->equals(VoucherCode::fromString($code->value)))->toBeTrue();
});
