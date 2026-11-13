<?php

declare(strict_types=1);

use Logistics\Returns\Domain\ValueObject\Disposition;

/**
 * Cobertura exhaustiva del value object Disposition: las tres etiquetas en inglés (los brazos del match que
 * ValueObjectsTest no ejercitaba) y las reglas de dueño / re-ingreso de stock de cada caso.
 */
it('expone la etiqueta en inglés de cada disposición', function (Disposition $case, string $label): void {
    expect($case->label())->toBe($label);
})->with([
    'restock' => [Disposition::Restock, 'Restock'],
    'return to seller' => [Disposition::ReturnToSeller, 'Return to seller'],
    'scrap' => [Disposition::Scrap, 'Scrap'],
]);

it('solo devolver al seller exige un dueño', function (): void {
    expect(Disposition::ReturnToSeller->requiresOwner())->toBeTrue()
        ->and(Disposition::Restock->requiresOwner())->toBeFalse()
        ->and(Disposition::Scrap->requiresOwner())->toBeFalse();
});

it('solo restock re-ingresa stock', function (): void {
    expect(Disposition::Restock->isRestock())->toBeTrue()
        ->and(Disposition::ReturnToSeller->isRestock())->toBeFalse()
        ->and(Disposition::Scrap->isRestock())->toBeFalse();
});

it('se construye desde su valor crudo', function (): void {
    expect(Disposition::from('restock'))->toBe(Disposition::Restock)
        ->and(Disposition::from('return_to_seller'))->toBe(Disposition::ReturnToSeller)
        ->and(Disposition::from('scrap'))->toBe(Disposition::Scrap);
});
