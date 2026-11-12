<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Measurement;

use Logistics\Shipments\Application\Contract\CatalogMeasurementsReader;
use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;
use Logistics\Shipments\Domain\ValueObject\LineMeasure;
use Logistics\Shipments\Domain\ValueObject\MeasurementsSource;
use Throwable;

/**
 * Resuelve la foto de medidas por unidad de cada línea con la cascada de Roadmap 02 (Fase 2.1):
 *
 *     producto (catálogo) → defaults de la categoría → default global (config)
 *
 * La cascada es POR CAMPO: el peso y el volumen se resuelven por separado (un producto con peso pero
 * sin dimensiones toma su peso y el volumen de la categoría o del default). La fuente de la línea es la
 * MÁS DÉBIL de las dos: `product` solo si ambos salieron del producto; `default` si alguno cayó al
 * default global (flag de dato incompleto).
 *
 * Nunca falla: si el catálogo no responde, todas las líneas caen al default con fuente `default`.
 * Faltan medidas → no se bloquea nada (decisión del dueño).
 */
final readonly class LineMeasureResolver
{
    /** Peso por unidad cuando no hay ningún dato (kg). Igual al default de planning. */
    public const FALLBACK_WEIGHT_KG = 10.0;

    /** Volumen por unidad cuando no hay ningún dato (m³). Igual al default de planning. */
    public const FALLBACK_VOLUME_M3 = 0.05;

    private float $defaultWeightKg;

    private float $defaultVolumeM3;

    public function __construct(
        private CatalogMeasurementsReader $catalog,
        float $defaultWeightKg = self::FALLBACK_WEIGHT_KG,
        float $defaultVolumeM3 = self::FALLBACK_VOLUME_M3,
    ) {
        // Un default mal configurado (0, negativo, no finito) no puede romper la ingesta.
        $this->defaultWeightKg = is_finite($defaultWeightKg) && $defaultWeightKg > 0.0
            ? $defaultWeightKg
            : self::FALLBACK_WEIGHT_KG;
        $this->defaultVolumeM3 = is_finite($defaultVolumeM3) && $defaultVolumeM3 > 0.0
            ? $defaultVolumeM3
            : self::FALLBACK_VOLUME_M3;
    }

    /**
     * Foto de medidas para cada SKU (una sola lectura al catálogo para el lote entero), indexada por la
     * clave normalizada ({@see CatalogMeasurements::keyOf()}). Cada SKU pedido tiene resultado.
     *
     * @param  list<string>               $skus
     * @return array<string, LineMeasure>
     */
    public function resolveMany(array $skus): array
    {
        $keys = array_values(array_unique(array_map(
            static fn (string $sku): string => CatalogMeasurements::keyOf($sku),
            $skus,
        )));

        try {
            $known = $keys === [] ? [] : $this->catalog->forSkus($keys);
        } catch (Throwable) {
            // El puerto no debería lanzar; si lo hace, se degrada al default (nunca bloquea el ingreso).
            $known = [];
        }

        $measures = [];
        foreach ($keys as $key) {
            $measures[$key] = $this->resolve($known[$key] ?? null);
        }

        return $measures;
    }

    /**
     * Foto de medidas de una sola línea según lo que conoce el catálogo (null = SKU desconocido).
     */
    public function resolve(?CatalogMeasurements $known): LineMeasure
    {
        [$weight, $weightSource] = $this->pick(
            $known?->productWeightKg,
            $known?->categoryWeightKg,
            $this->defaultWeightKg,
        );
        [$volume, $volumeSource] = $this->pick(
            $known?->productVolumeM3,
            $known?->categoryVolumeM3,
            $this->defaultVolumeM3,
        );

        try {
            return LineMeasure::of($weight, $volume, $weightSource->weakest($volumeSource));
        } catch (Throwable) {
            return $this->fallback();
        }
    }

    /**
     * Foto 100 % default (dato incompleto marcado).
     */
    public function fallback(): LineMeasure
    {
        return LineMeasure::of($this->defaultWeightKg, $this->defaultVolumeM3, MeasurementsSource::Default);
    }

    /**
     * Primer valor válido de la cascada y su fuente.
     *
     * @return array{0: float, 1: MeasurementsSource}
     */
    private function pick(?float $product, ?float $category, float $default): array
    {
        if (self::usable($product)) {
            return [(float) $product, MeasurementsSource::Product];
        }
        if (self::usable($category)) {
            return [(float) $category, MeasurementsSource::Category];
        }

        return [$default, MeasurementsSource::Default];
    }

    private static function usable(?float $value): bool
    {
        return $value !== null && is_finite($value) && $value > 0.0;
    }
}
