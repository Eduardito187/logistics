<?php

declare(strict_types=1);

use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;

/**
 * Referencia a un corredor origen→destino con tipo: normaliza espacios, valida y expone forma primitiva.
 */
it('construye un corredor válido recortando los espacios', function (): void {
    $ref = CorridorRef::of('  CD-SCZ ', ' CD-LPZ  ', 'cd_to_cd');

    expect($ref->from)->toBe('CD-SCZ')
        ->and($ref->to)->toBe('CD-LPZ')
        ->and($ref->type)->toBe('cd_to_cd')
        ->and($ref->toArray())->toBe(['from' => 'CD-SCZ', 'to' => 'CD-LPZ', 'type' => 'cd_to_cd']);
});

it('acepta todos los tipos de corredor válidos', function (string $type): void {
    expect(CorridorRef::of('A', 'B', $type)->type)->toBe($type);
})->with([
    'cd_to_customer',
    'cd_to_cd',
    'cd_to_store',
    'store_to_store',
    'store_to_cd',
    'store_pickup',
]);

it('compara dos corredores por sus tres componentes', function (): void {
    $base = CorridorRef::of('A', 'B', 'cd_to_cd');

    expect($base->equals(CorridorRef::of('A', 'B', 'cd_to_cd')))->toBeTrue()
        ->and($base->equals(CorridorRef::of('A', 'C', 'cd_to_cd')))->toBeFalse()
        ->and($base->equals(CorridorRef::of('A', 'B', 'cd_to_store')))->toBeFalse();
});

it('rechaza un origen vacío', function (): void {
    expect(fn () => CorridorRef::of('   ', 'B', 'cd_to_cd'))
        ->toThrow(CarriersException::class, 'origen del corredor no puede estar vacío');
});

it('rechaza un destino vacío', function (): void {
    expect(fn () => CorridorRef::of('A', '', 'cd_to_cd'))
        ->toThrow(CarriersException::class, 'destino del corredor no puede estar vacío');
});

it('rechaza un tipo de corredor inválido', function (): void {
    expect(fn () => CorridorRef::of('A', 'B', 'air_freight'))
        ->toThrow(CarriersException::class, 'Tipo de corredor inválido');
});
