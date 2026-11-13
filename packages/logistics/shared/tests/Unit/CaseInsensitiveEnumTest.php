<?php

declare(strict_types=1);

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Enum de prueba que usa el trait: estados con backing value en MAYÚSCULA.
 */
enum CieStatusFixture: string
{
    use CaseInsensitiveEnum;

    case Pending = 'PENDING';
    case Delivered = 'DELIVERED';
}

it('resuelve el estado sin importar la caja (mayúscula, minúscula, mixta) y con espacios', function (): void {
    expect(CieStatusFixture::fromLoose('DELIVERED'))->toBe(CieStatusFixture::Delivered)
        ->and(CieStatusFixture::fromLoose('delivered'))->toBe(CieStatusFixture::Delivered)
        ->and(CieStatusFixture::fromLoose('Delivered'))->toBe(CieStatusFixture::Delivered)
        ->and(CieStatusFixture::fromLoose('  pending  '))->toBe(CieStatusFixture::Pending);
});

it('sigue VALIDANDO: un estado inexistente lanza ValueError', function (): void {
    expect(fn () => CieStatusFixture::fromLoose('nope'))->toThrow(ValueError::class);
});

it('tryFromLoose devuelve null ante un estado inválido, vacío o null (sin lanzar)', function (): void {
    expect(CieStatusFixture::tryFromLoose('nope'))->toBeNull()
        ->and(CieStatusFixture::tryFromLoose(''))->toBeNull()
        ->and(CieStatusFixture::tryFromLoose('   '))->toBeNull()
        ->and(CieStatusFixture::tryFromLoose(null))->toBeNull()
        ->and(CieStatusFixture::tryFromLoose('pending'))->toBe(CieStatusFixture::Pending);
});

it('no cambia la caja canónica del backing value', function (): void {
    expect(CieStatusFixture::fromLoose('delivered')->value)->toBe('DELIVERED');
});
