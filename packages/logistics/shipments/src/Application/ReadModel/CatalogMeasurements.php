<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Medidas que el catálogo conoce para un SKU, en primitivos: las del producto y los defaults de su
 * categoría. Cualquier valor puede faltar (null). El volumen solo viene cuando están las tres
 * dimensiones (L × A × H / 1e6).
 */
final readonly class CatalogMeasurements
{
    public function __construct(
        public ?float $productWeightKg,
        public ?float $productVolumeM3,
        public ?float $categoryWeightKg = null,
        public ?float $categoryVolumeM3 = null,
    ) {}

    /**
     * Clave normalizada del SKU para cruzar con el catálogo: el SKU canónico del catálogo se guarda en
     * mayúsculas y sin espacios externos; el de shipments conserva el formato de la fuente.
     */
    public static function keyOf(string $sku): string
    {
        return strtoupper(trim($sku));
    }
}
