<?php

declare(strict_types=1);

use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\EmailAddress;

// VO EmailAddress: valida con filter_var y normaliza a minúsculas para unicidad insensible a mayúsculas.

it('valida y normaliza el correo a minúsculas', function (): void {
    $email = EmailAddress::fromString('  User@Dismac.Com ');

    expect($email->value)->toBe('user@dismac.com');
});

it('rechaza correos inválidos', function (string $value): void {
    expect(fn () => EmailAddress::fromString($value))->toThrow(AccessException::class);
})->with(['', 'no-arroba', 'a@', '@dismac.com', 'a b@dismac.com']);

it('considera iguales dos correos que solo difieren en mayúsculas', function (): void {
    expect(EmailAddress::fromString('user@dismac.com')->equals(EmailAddress::fromString('USER@DISMAC.COM')))->toBeTrue();
});
