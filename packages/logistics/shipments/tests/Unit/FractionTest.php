<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\Fraction;

it('builds a valid "N de M" fraction', function (): void {
    $fraction = Fraction::of(2, 3);

    expect($fraction->index)->toBe(2)
        ->and($fraction->total)->toBe(3)
        ->and($fraction->toCanonical())->toBe('2/3')
        ->and($fraction->toArray())->toBe([2, 3]);
});

it('builds the single (1 de 1) case', function (): void {
    expect(Fraction::single()->toCanonical())->toBe('1/1');
});

it('compares fractions by value', function (): void {
    expect(Fraction::of(1, 2)->equals(Fraction::of(1, 2)))->toBeTrue()
        ->and(Fraction::of(1, 2)->equals(Fraction::of(2, 2)))->toBeFalse();
});

it('rejects malformed fractions', function (callable $build, string $reason): void {
    expect($build)->toThrow(ShipmentsException::class, $reason);
})->with([
    'total below range' => [fn () => Fraction::of(1, 0), 'total fuera de rango'],
    'total above range' => [fn () => Fraction::of(1, 1000), 'total fuera de rango'],
    'index below one' => [fn () => Fraction::of(0, 3), 'índice'],
    'index above total' => [fn () => Fraction::of(4, 3), 'índice'],
]);
