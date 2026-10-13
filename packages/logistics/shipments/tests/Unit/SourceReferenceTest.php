<?php

declare(strict_types=1);

use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

it('pairs a source with its external id', function (): void {
    $reference = SourceReference::of(OrderSource::Magento, 'M-12345');

    expect($reference->source)->toBe(OrderSource::Magento)
        ->and($reference->externalId)->toBe('M-12345')
        ->and($reference->toCanonical())->toBe('MAGENTO:M-12345');
});

it('trims the external id', function (): void {
    expect(SourceReference::of(OrderSource::Sap, '  DM-1  ')->externalId)->toBe('DM-1');
});

it('is the idempotency key: equal only when both source and id match', function (): void {
    $a = SourceReference::of(OrderSource::Sap, 'DM-1');

    expect($a->equals(SourceReference::of(OrderSource::Sap, 'DM-1')))->toBeTrue()
        ->and($a->equals(SourceReference::of(OrderSource::Sap, 'DM-2')))->toBeFalse()
        ->and($a->equals(SourceReference::of(OrderSource::Magento, 'DM-1')))->toBeFalse();
});

it('rejects an empty external id', function (): void {
    expect(fn () => SourceReference::of(OrderSource::Manual, '   '))
        ->toThrow(ShipmentsException::class, 'no puede estar vacío');
});
