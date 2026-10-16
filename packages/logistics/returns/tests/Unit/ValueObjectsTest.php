<?php

declare(strict_types=1);

use Logistics\Returns\Domain\Exception\ReturnsException;
use Logistics\Returns\Domain\ValueObject\Disposition;
use Logistics\Returns\Domain\ValueObject\ReturnKind;
use Logistics\Returns\Domain\ValueObject\ReturnLine;
use Logistics\Returns\Domain\ValueObject\ReturnStatus;

it('crea una línea de devolución válida', function (): void {
    $line = ReturnLine::of('  BEL-TV-55  ', 3, '  damaged  ');

    expect($line->sku)->toBe('BEL-TV-55')
        ->and($line->quantity)->toBe(3)
        ->and($line->reason)->toBe('damaged')
        ->and($line->toArray())->toBe(['sku' => 'BEL-TV-55', 'quantity' => 3, 'reason' => 'damaged']);
});

it('rechaza líneas inválidas', function (callable $build): void {
    expect($build)->toThrow(ReturnsException::class);
})->with([
    'sku vacío' => [fn () => ReturnLine::of('   ', 1, 'r')],
    'cantidad cero' => [fn () => ReturnLine::of('X', 0, 'r')],
    'cantidad negativa' => [fn () => ReturnLine::of('X', -2, 'r')],
    'motivo vacío' => [fn () => ReturnLine::of('X', 1, '   ')],
]);

it('dos líneas iguales son equals', function (): void {
    expect(ReturnLine::of('X', 1, 'r')->equals(ReturnLine::of('X', 1, 'r')))->toBeTrue()
        ->and(ReturnLine::of('X', 1, 'r')->equals(ReturnLine::of('X', 2, 'r')))->toBeFalse();
});

it('los enums exponen label en inglés y reglas de dueño', function (): void {
    expect(ReturnKind::ReturnToVendor->requiresOwner())->toBeTrue()
        ->and(ReturnKind::CustomerReturn->requiresOwner())->toBeFalse()
        ->and(ReturnKind::CustomerReturn->label())->toBe('Customer return')
        ->and(Disposition::ReturnToSeller->requiresOwner())->toBeTrue()
        ->and(Disposition::Restock->requiresOwner())->toBeFalse()
        ->and(Disposition::Restock->isRestock())->toBeTrue()
        ->and(Disposition::Scrap->isRestock())->toBeFalse()
        ->and(ReturnStatus::Dispositioned->label())->toBe('Dispositioned');
});
