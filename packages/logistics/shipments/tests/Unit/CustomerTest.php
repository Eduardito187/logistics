<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\Customer;

it('keeps name and contact details, trimming whitespace', function (): void {
    $customer = Customer::of('  Juana Perez  ', ' 6284531 LP ', ' +591 71544820 ', ' juana@gmail.com ');

    expect($customer->name)->toBe('Juana Perez')
        ->and($customer->documentId)->toBe('6284531 LP')
        ->and($customer->phone)->toBe('+591 71544820')
        ->and($customer->email)->toBe('juana@gmail.com');
});

it('treats empty contact details as absent (null)', function (): void {
    $customer = Customer::of('Juana Perez', '', '   ', null);

    expect($customer->documentId)->toBeNull()
        ->and($customer->phone)->toBeNull()
        ->and($customer->email)->toBeNull();
});

it('rejects an empty customer name', function (): void {
    expect(fn () => Customer::of('   '))
        ->toThrow(ShipmentsException::class, 'nombre del cliente no puede estar vacío');
});

it('rejects a name longer than the maximum length', function (): void {
    expect(fn () => Customer::of(str_repeat('a', 181)))
        ->toThrow(ShipmentsException::class, 'nombre del cliente excede');
});
