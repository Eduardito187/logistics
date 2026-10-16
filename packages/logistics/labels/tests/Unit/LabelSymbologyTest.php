<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\LabelSymbology;

it('tells which marks each symbology includes', function (): void {
    expect(LabelSymbology::Qr->includesQr())->toBeTrue()
        ->and(LabelSymbology::Qr->includesBarcode())->toBeFalse()
        ->and(LabelSymbology::Barcode->includesQr())->toBeFalse()
        ->and(LabelSymbology::Barcode->includesBarcode())->toBeTrue()
        ->and(LabelSymbology::Both->includesQr())->toBeTrue()
        ->and(LabelSymbology::Both->includesBarcode())->toBeTrue();
});

it('parses primitive values tolerantly, defaulting to BOTH', function (): void {
    expect(LabelSymbology::fromValue('qr'))->toBe(LabelSymbology::Qr)
        ->and(LabelSymbology::fromValue(' BARCODE '))->toBe(LabelSymbology::Barcode)
        ->and(LabelSymbology::fromValue('both'))->toBe(LabelSymbology::Both)
        ->and(LabelSymbology::fromValue('unknown'))->toBe(LabelSymbology::Both)
        ->and(LabelSymbology::fromValue(null))->toBe(LabelSymbology::Both)
        ->and(LabelSymbology::fromValue(42))->toBe(LabelSymbology::Both);
});
