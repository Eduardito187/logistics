<?php

declare(strict_types=1);

use Logistics\Sellers\Domain\Exception\SellersException;
use Logistics\Sellers\Domain\ValueObject\ContactInfo;

/**
 * Cobertura del value object ContactInfo del contexto sellers: validación de nombre y correo, teléfono
 * opcional normalizado, igualdad y serialización primitiva. PHP puro, sin Laravel.
 */
it('arma un contacto con teléfono y lo expone en su forma primitiva', function (): void {
    $contacto = ContactInfo::of('  Ana Pérez  ', 'ana@seller.bo', '  70011001 ');

    expect($contacto->contactName)->toBe('Ana Pérez')
        ->and($contacto->email)->toBe('ana@seller.bo')
        ->and($contacto->phone)->toBe('70011001')
        ->and($contacto->toArray())->toBe([
            'contact_name' => 'Ana Pérez',
            'email' => 'ana@seller.bo',
            'phone' => '70011001',
        ]);
});

it('deja el teléfono en null cuando viene vacío o ausente', function (): void {
    expect(ContactInfo::of('Ana', 'ana@seller.bo')->phone)->toBeNull()
        ->and(ContactInfo::of('Ana', 'ana@seller.bo', '   ')->phone)->toBeNull();
});

it('rechaza nombre vacío y correo inválido', function (): void {
    expect(fn () => ContactInfo::of('   ', 'ana@seller.bo'))->toThrow(SellersException::class);
    expect(fn () => ContactInfo::of('Ana', 'no-es-correo'))->toThrow(SellersException::class);
    expect(fn () => ContactInfo::of('Ana', '  '))->toThrow(SellersException::class);
});

it('compara por valor', function (): void {
    $base = ContactInfo::of('Ana', 'ana@seller.bo', '70011001');

    expect($base->equals(ContactInfo::of('Ana', 'ana@seller.bo', '70011001')))->toBeTrue()
        ->and($base->equals(ContactInfo::of('Ana', 'ana@seller.bo')))->toBeFalse()
        ->and($base->equals(ContactInfo::of('Otro', 'ana@seller.bo', '70011001')))->toBeFalse()
        ->and($base->equals(ContactInfo::of('Ana', 'otra@seller.bo', '70011001')))->toBeFalse();
});
