<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\QcOutcome;
use Logistics\Inbound\Domain\ValueObject\ReceiptLine;
use Logistics\Inbound\Domain\ValueObject\ReceivedQuantity;

it('recorta el sku y rechaza uno vacío', function (): void {
    $line = ReceiptLine::of('  BEL-TV-55  ', ReceivedQuantity::of(3), QcOutcome::Accepted);

    expect($line->sku)->toBe('BEL-TV-55')
        ->and(fn () => ReceiptLine::of('   ', ReceivedQuantity::of(3), QcOutcome::Accepted))
        ->toThrow(InboundException::class);
});

it('compara dos líneas recibidas por sku, cantidad y QC', function (): void {
    $line = ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage);

    expect($line->equals(ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage)))->toBeTrue()
        ->and($line->equals(ReceiptLine::of('OTHER', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage)))->toBeFalse()
        ->and($line->equals(ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(8, 0, 2), QcOutcome::PartialDamage)))->toBeFalse()
        ->and($line->equals(ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9), QcOutcome::Accepted)))->toBeFalse();
});

it('serializa la línea recibida a primitivos', function (): void {
    $line = ReceiptLine::of('BEL-TV-55', ReceivedQuantity::of(9, 0, 1), QcOutcome::PartialDamage);

    expect($line->toArray())->toBe([
        'sku' => 'BEL-TV-55',
        'quantity' => ['accepted' => 9, 'rejected' => 0, 'damaged' => 1],
        'qc_outcome' => 'partial_damage',
    ]);
});
