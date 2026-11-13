<?php

declare(strict_types=1);

use Logistics\Labels\Domain\ValueObject\LabelSizeProfile;

it('exposes the physical dimensions of each profile in millimetres', function (): void {
    expect(LabelSizeProfile::Standard->widthMm())->toBe(100)
        ->and(LabelSizeProfile::Standard->heightMm())->toBe(75)
        ->and(LabelSizeProfile::Lite->widthMm())->toBe(50)
        ->and(LabelSizeProfile::Lite->heightMm())->toBe(25)
        ->and(LabelSizeProfile::Lite40x30->widthMm())->toBe(40)
        ->and(LabelSizeProfile::Lite40x30->heightMm())->toBe(30)
        ->and(LabelSizeProfile::Micro->widthMm())->toBe(30)
        ->and(LabelSizeProfile::Micro->heightMm())->toBe(15);
});

it('computes dots from millimetres and dpi (mm x dpi / 25.4)', function (): void {
    // 50 mm a 203 dpi = 399,6 -> 400; a 300 dpi = 590,5 -> 591.
    expect(LabelSizeProfile::Lite->widthDots(203))->toBe(400)
        ->and(LabelSizeProfile::Lite->widthDots(300))->toBe(591)
        ->and(LabelSizeProfile::Lite->heightDots(203))->toBe(200)
        // Standard a 203: 100 mm -> 799 dots, 75 mm -> 599 (el layout histórico usa 799/0609 fijos).
        ->and(LabelSizeProfile::Standard->widthDots(203))->toBe(799);
});

it('marks only Standard as carrying the signed QR and the Code128', function (): void {
    expect(LabelSizeProfile::Standard->carriesSignedQr())->toBeTrue()
        ->and(LabelSizeProfile::Standard->carriesBarcode())->toBeTrue()
        ->and(LabelSizeProfile::Lite->carriesSignedQr())->toBeFalse()
        ->and(LabelSizeProfile::Lite->carriesBarcode())->toBeFalse();
});

it('shows the product name on every profile except Micro', function (): void {
    expect(LabelSizeProfile::Standard->showsProductName())->toBeTrue()
        ->and(LabelSizeProfile::Lite->showsProductName())->toBeTrue()
        ->and(LabelSizeProfile::Lite40x30->showsProductName())->toBeTrue()
        ->and(LabelSizeProfile::Micro->showsProductName())->toBeFalse();
});

it('degrades any unknown or non-string value to the Standard default', function (): void {
    expect(LabelSizeProfile::fromValue('nonsense'))->toBe(LabelSizeProfile::Standard)
        ->and(LabelSizeProfile::fromValue(null))->toBe(LabelSizeProfile::Standard)
        ->and(LabelSizeProfile::fromValue(42))->toBe(LabelSizeProfile::Standard)
        ->and(LabelSizeProfile::fromValue(' LITE '))->toBe(LabelSizeProfile::Lite)
        ->and(LabelSizeProfile::DEFAULT)->toBe(LabelSizeProfile::Standard)
        ->and(LabelSizeProfile::RECOMMENDED_SMALL)->toBe(LabelSizeProfile::Lite);
});
