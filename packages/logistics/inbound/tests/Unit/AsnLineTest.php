<?php

declare(strict_types=1);

use Logistics\Inbound\Domain\Exception\InboundException;
use Logistics\Inbound\Domain\ValueObject\AsnLine;

it('recorta el sku de la línea del ASN y rechaza uno vacío', function (): void {
    expect(AsnLine::of('  BEL-TV-55 ', 4)->sku)->toBe('BEL-TV-55')
        ->and(fn () => AsnLine::of('  ', 4))->toThrow(InboundException::class);
});

it('exige una cantidad esperada positiva', function (): void {
    expect(fn () => AsnLine::of('X', 0))->toThrow(InboundException::class)
        ->and(fn () => AsnLine::of('X', -3))->toThrow(InboundException::class);
});

it('compara dos líneas de ASN por sku y cantidad esperada', function (): void {
    $line = AsnLine::of('BEL-TV-55', 10);

    expect($line->equals(AsnLine::of('BEL-TV-55', 10)))->toBeTrue()
        ->and($line->equals(AsnLine::of('BEL-TV-55', 9)))->toBeFalse()
        ->and($line->equals(AsnLine::of('OTHER', 10)))->toBeFalse();
});

it('serializa la línea del ASN a primitivos', function (): void {
    expect(AsnLine::of('BEL-TV-55', 10)->toArray())->toBe([
        'sku' => 'BEL-TV-55',
        'expected_quantity' => 10,
    ]);
});
