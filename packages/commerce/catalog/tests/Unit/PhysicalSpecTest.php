<?php

declare(strict_types=1);

use Commerce\Catalog\Domain\Event\ProductPhysicalSpecChanged;
use Commerce\Catalog\Domain\Exception\CatalogException;
use Commerce\Catalog\Domain\Model\Product;
use Commerce\Catalog\Domain\ValueObject\PhysicalSpec;
use Commerce\Catalog\Domain\ValueObject\ProductId;
use Commerce\Catalog\Domain\ValueObject\Sku;

it('PhysicalSpec completa calcula el volumen L×A×H/1e6', function (): void {
    $spec = PhysicalSpec::of(12.5, 60.0, 50.0, 40.0);

    expect($spec->weightKg)->toBe(12.5)
        ->and($spec->isComplete())->toBeTrue()
        ->and($spec->hasAllDimensions())->toBeTrue()
        ->and($spec->volumeM3())->toBe(0.12)
        ->and($spec->toArray())->toBe([
            'weight_kg' => 12.5,
            'length_cm' => 60.0,
            'width_cm' => 50.0,
            'height_cm' => 40.0,
            'volume_m3' => 0.12,
        ]);
});

it('PhysicalSpec admite fichas parciales y solo da volumen con las tres dimensiones', function (): void {
    $onlyWeight = PhysicalSpec::of(3.0);
    expect($onlyWeight->isComplete())->toBeFalse()
        ->and($onlyWeight->isEmpty())->toBeFalse()
        ->and($onlyWeight->volumeM3())->toBeNull();

    $twoDims = PhysicalSpec::of(null, 10.0, 10.0, null);
    expect($twoDims->hasAllDimensions())->toBeFalse()
        ->and($twoDims->volumeM3())->toBeNull();

    $dimsOnly = PhysicalSpec::of(null, 100.0, 100.0, 100.0);
    expect($dimsOnly->volumeM3())->toBe(1.0)
        ->and($dimsOnly->isComplete())->toBeFalse();

    expect(PhysicalSpec::empty()->isEmpty())->toBeTrue()
        ->and(PhysicalSpec::empty()->volumeM3())->toBeNull();
});

it('PhysicalSpec rechaza ceros, negativos, no finitos y valores fuera de rango', function (): void {
    expect(fn () => PhysicalSpec::of(0.0))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(-1.0))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(PhysicalSpec::MAX_WEIGHT_KG + 1))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(null, 0.0))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(null, null, -5.0))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(null, null, null, PhysicalSpec::MAX_DIMENSION_CM + 0.5))
        ->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(INF))->toThrow(CatalogException::class);
    expect(fn () => PhysicalSpec::of(NAN))->toThrow(CatalogException::class);
    // Positivo pero que redondea a 0 con la precisión de persistencia.
    expect(fn () => PhysicalSpec::of(0.0001))->toThrow(CatalogException::class);

    // Los topes son inclusivos.
    expect(PhysicalSpec::of(PhysicalSpec::MAX_WEIGHT_KG, PhysicalSpec::MAX_DIMENSION_CM)->weightKg)
        ->toBe(PhysicalSpec::MAX_WEIGHT_KG);
});

it('PhysicalSpec redondea a la precisión de persistencia y compara por valor', function (): void {
    $spec = PhysicalSpec::of(1.23456, 10.126, 10.0, 10.0);

    expect($spec->weightKg)->toBe(1.235)
        ->and($spec->lengthCm)->toBe(10.13)
        ->and($spec->equals(PhysicalSpec::of(1.235, 10.13, 10.0, 10.0)))->toBeTrue()
        ->and($spec->equals(PhysicalSpec::of(1.235)))->toBeFalse();
});

it('PhysicalSpec::fromArray tolera strings decimales y descarta datos sucios sin romper', function (): void {
    $spec = PhysicalSpec::fromArray([
        'weight_kg' => '7.500',
        'length_cm' => '30.00',
        'width_cm' => 'abc',
        'height_cm' => '-2',
    ]);

    expect($spec->weightKg)->toBe(7.5)
        ->and($spec->lengthCm)->toBe(30.0)
        ->and($spec->widthCm)->toBeNull()
        ->and($spec->heightCm)->toBeNull();

    expect(PhysicalSpec::fromArray([])->isEmpty())->toBeTrue();
});

it('Product define sus medidas y graba el evento solo si cambian', function (): void {
    $product = Product::draft(ProductId::generate(), null, 'Refrigerador', Sku::of('ref-01'));
    $product->releaseEvents();

    expect($product->physicalSpec()->isEmpty())->toBeTrue();

    $product->definePhysicalSpec(PhysicalSpec::of(80.0, 70.0, 60.0, 180.0));
    $events = $product->releaseEvents();

    expect($events)->toHaveCount(1)
        ->and($events[0])->toBeInstanceOf(ProductPhysicalSpecChanged::class)
        ->and($events[0]->eventName())->toBe('catalog.product_physical_spec_changed')
        ->and($events[0]->payload()['canonical_sku'])->toBe('REF-01')
        ->and($events[0]->payload()['volume_m3'])->toBe(0.756);

    // Idempotente: la misma ficha no graba otro evento.
    $product->definePhysicalSpec(PhysicalSpec::of(80.0, 70.0, 60.0, 180.0));
    expect($product->releaseEvents())->toBe([]);
});
