<?php

declare(strict_types=1);

use Logistics\Shipments\Application\Contract\CatalogMeasurementsReader;
use Logistics\Shipments\Application\Measurement\LineMeasureResolver;
use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;
use Logistics\Shipments\Domain\Exception\ShipmentsException;
use Logistics\Shipments\Domain\ValueObject\LineMeasure;
use Logistics\Shipments\Domain\ValueObject\MeasurementsSource;
use Logistics\Shipments\Tests\Unit\FakeCatalogReaderOutage;

/**
 * Lector de catálogo en memoria para probar la cascada sin base de datos.
 *
 * @param array<string, CatalogMeasurements> $known
 */
function fakeCatalogReader(array $known, bool $throws = false): CatalogMeasurementsReader
{
    return new class($known, $throws) implements CatalogMeasurementsReader
    {
        /**
         * @param array<string, CatalogMeasurements> $known
         */
        public function __construct(private array $known, private bool $throws) {}

        public function forSkus(array $skus): array
        {
            if ($this->throws) {
                throw new FakeCatalogReaderOutage('catálogo caído');
            }

            return array_intersect_key($this->known, array_flip($skus));
        }
    };
}

it('usa las medidas del producto cuando están completas (fuente product)', function (): void {
    $resolver = new LineMeasureResolver(fakeCatalogReader([
        'LAV-10' => new CatalogMeasurements(65.5, 0.3315, 40.0, 0.2),
    ]));

    $measure = $resolver->resolveMany(['lav-10'])['LAV-10'];

    expect($measure->unitWeightKg)->toBe(65.5)
        ->and($measure->unitVolumeM3)->toBe(0.3315)
        ->and($measure->source)->toBe(MeasurementsSource::Product)
        ->and($measure->isDefaulted())->toBeFalse();
});

it('cae a la categoría por campo y marca la fuente más débil', function (): void {
    $resolver = new LineMeasureResolver(fakeCatalogReader([
        'TV-55' => new CatalogMeasurements(18.0, null, 30.0, 0.2),
    ]));

    $measure = $resolver->resolveMany(['TV-55'])['TV-55'];

    expect($measure->unitWeightKg)->toBe(18.0)
        ->and($measure->unitVolumeM3)->toBe(0.2)
        ->and($measure->source)->toBe(MeasurementsSource::Category);
});

it('cae al default global con fuente default cuando el SKU no existe o le faltan datos', function (): void {
    $resolver = new LineMeasureResolver(fakeCatalogReader([
        'SOLO-PESO' => new CatalogMeasurements(3.0, null),
    ]), 10.0, 0.05);

    $measures = $resolver->resolveMany(['DESCONOCIDO', 'SOLO-PESO']);

    expect($measures['DESCONOCIDO']->unitWeightKg)->toBe(10.0)
        ->and($measures['DESCONOCIDO']->unitVolumeM3)->toBe(0.05)
        ->and($measures['DESCONOCIDO']->source)->toBe(MeasurementsSource::Default)
        ->and($measures['SOLO-PESO']->unitWeightKg)->toBe(3.0)
        ->and($measures['SOLO-PESO']->unitVolumeM3)->toBe(0.05)
        ->and($measures['SOLO-PESO']->source)->toBe(MeasurementsSource::Default);
});

it('nunca falla: catálogo caído o defaults mal configurados degradan al default seguro', function (): void {
    $resolver = new LineMeasureResolver(fakeCatalogReader([], throws: true), 0.0, -1.0);

    $measure = $resolver->resolveMany(['X-1'])['X-1'];

    expect($measure->unitWeightKg)->toBe(LineMeasureResolver::FALLBACK_WEIGHT_KG)
        ->and($measure->unitVolumeM3)->toBe(LineMeasureResolver::FALLBACK_VOLUME_M3)
        ->and($measure->source)->toBe(MeasurementsSource::Default);

    expect($resolver->resolveMany([]))->toBe([]);
});

it('LineMeasure exige valores positivos y calcula totales por cantidad', function (): void {
    $measure = LineMeasure::of(2.5, 0.01, MeasurementsSource::Product);

    expect($measure->totalWeightKg(4))->toBe(10.0)
        ->and($measure->totalVolumeM3(3))->toBe(0.03);

    expect(fn () => LineMeasure::of(0.0, 0.01, MeasurementsSource::Product))->toThrow(ShipmentsException::class);
    expect(fn () => LineMeasure::of(1.0, -0.01, MeasurementsSource::Product))->toThrow(ShipmentsException::class);
});
