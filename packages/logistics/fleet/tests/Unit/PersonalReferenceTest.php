<?php

declare(strict_types=1);

use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\PersonalReference;

/**
 * Referencia personal de un chofer/ayudante: solo el nombre es obligatorio.
 */
it('recorta el nombre y limpia los opcionales vacíos a null', function (): void {
    $reference = PersonalReference::of('  Ana  ', '  ', '', '   ');

    expect($reference->toArray())->toBe([
        'name' => 'Ana',
        'relationship' => null,
        'phone' => null,
        'note' => null,
    ]);
});

it('conserva los opcionales recortados cuando traen valor', function (): void {
    $reference = PersonalReference::of('Mario', '  Hermano ', ' 70011122 ', ' Vecino ');

    expect($reference->toArray())->toBe([
        'name' => 'Mario',
        'relationship' => 'Hermano',
        'phone' => '70011122',
        'note' => 'Vecino',
    ]);
});

it('rechaza una referencia sin nombre', function (): void {
    expect(fn () => PersonalReference::of('   '))
        ->toThrow(FleetException::class, 'nombre de la referencia no puede estar vacío');
});
