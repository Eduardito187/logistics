<?php

declare(strict_types=1);

use Logistics\Inventory\Domain\ValueObject\MovementType;

/**
 * Enum del tipo de movimiento del ledger: valores estables (contrato con la tabla `inventory_movements`)
 * y etiquetas legibles en inglés para el admin. PHP puro, sin Laravel.
 */
it('mapea cada caso a su valor estable de la columna movement_type', function (): void {
    expect(MovementType::Received->value)->toBe('received')
        ->and(MovementType::Reserved->value)->toBe('reserved')
        ->and(MovementType::Released->value)->toBe('released')
        ->and(MovementType::Picked->value)->toBe('picked')
        ->and(MovementType::Transferred->value)->toBe('transferred')
        ->and(MovementType::Adjusted->value)->toBe('adjusted');
});

it('reconstruye el caso desde su valor persistido', function (): void {
    expect(MovementType::from('received'))->toBe(MovementType::Received)
        ->and(MovementType::from('adjusted'))->toBe(MovementType::Adjusted)
        ->and(MovementType::tryFrom('inexistente'))->toBeNull();
});

it('expone una etiqueta legible en inglés para cada caso', function (MovementType $type, string $label): void {
    expect($type->label())->toBe($label);
})->with([
    'received' => [MovementType::Received, 'Received'],
    'reserved' => [MovementType::Reserved, 'Reserved'],
    'released' => [MovementType::Released, 'Released'],
    'picked' => [MovementType::Picked, 'Picked'],
    'transferred' => [MovementType::Transferred, 'Transferred'],
    'adjusted' => [MovementType::Adjusted, 'Adjusted'],
]);

it('cubre los seis casos del ledger sin huérfanos', function (): void {
    expect(MovementType::cases())->toHaveCount(6);
});
