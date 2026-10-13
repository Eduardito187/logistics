<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\AddressConfidenceLevel;

it('derives the level from the score by thresholds', function (int $score, AddressConfidenceLevel $level): void {
    $confidence = AddressConfidence::of($score);

    expect($confidence->score)->toBe($score)
        ->and($confidence->level)->toBe($level);
})->with([
    'top is high' => [100, AddressConfidenceLevel::High],
    'high threshold boundary' => [70, AddressConfidenceLevel::High],
    'just below high is medium' => [69, AddressConfidenceLevel::Medium],
    'medium threshold boundary' => [40, AddressConfidenceLevel::Medium],
    'just below medium is low' => [39, AddressConfidenceLevel::Low],
    'zero is low' => [0, AddressConfidenceLevel::Low],
]);

it('flags low confidence addresses', function (): void {
    expect(AddressConfidence::of(10)->isLow())->toBeTrue()
        ->and(AddressConfidence::of(50)->isLow())->toBeFalse();
});

it('rejects scores outside 0..100', function (int $score): void {
    expect(fn () => AddressConfidence::of($score))
        ->toThrow(ShipmentsException::class, 'fuera de rango');
})->with([
    'negative' => [-1],
    'above hundred' => [101],
]);
