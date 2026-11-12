<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shipments\Application\Contract\CatalogMeasurementsReader;
use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;
use Throwable;

/**
 * Lee las medidas físicas del catálogo (`commerce_products` + `commerce_categories`) en SOLO lectura por
 * el query builder, sin depender del dominio de commerce/catalog (sin composer require): el contrato es
 * el nombre de tablas y columnas. Una sola consulta por lote de SKUs (whereIn sobre el índice único de
 * `canonical_sku`).
 *
 * Tolerante: corre dentro de un SAVEPOINT (transacción anidada) para que, si el catálogo no está
 * desplegado o la consulta falla, la transacción del ingreso en PostgreSQL NO quede abortada; ante
 * cualquier error devuelve [] y el resolutor cae al default global.
 *
 * Solo el SKU canónico del producto: los SKUs de variantes (JSONB) no se buscan acá para no escanear la
 * tabla en cada ingreso; caen a la categoría/default.
 */
final readonly class CatalogTablesMeasurementsReader implements CatalogMeasurementsReader
{
    private const PRODUCTS_TABLE = 'commerce_products';

    private const CATEGORIES_TABLE = 'commerce_categories';

    /** Tope de SKUs por consulta (el whereIn se parte en tramos). */
    private const CHUNK = 500;

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forSkus(array $skus): array
    {
        $keys = array_values(array_unique(array_filter(
            array_map(static fn (string $sku): string => CatalogMeasurements::keyOf($sku), $skus),
            static fn (string $key): bool => $key !== '',
        )));

        if ($keys === []) {
            return [];
        }

        try {
            /** @var array<string, CatalogMeasurements> $result */
            $result = $this->connection->transaction(function () use ($keys): array {
                $found = [];
                foreach (array_chunk($keys, self::CHUNK) as $chunk) {
                    $rows = $this->connection->table(self::PRODUCTS_TABLE.' as p')
                        ->leftJoin(self::CATEGORIES_TABLE.' as c', 'c.id', '=', 'p.category_id')
                        ->whereIn('p.canonical_sku', $chunk)
                        ->get([
                            'p.canonical_sku',
                            'p.weight_kg',
                            'p.length_cm',
                            'p.width_cm',
                            'p.height_cm',
                            'c.weight_kg as category_weight_kg',
                            'c.length_cm as category_length_cm',
                            'c.width_cm as category_width_cm',
                            'c.height_cm as category_height_cm',
                        ]);

                    foreach ($rows as $row) {
                        /** @var \stdClass $row */
                        $sku = $row->canonical_sku ?? null;
                        if (!is_string($sku)) {
                            continue;
                        }

                        $found[CatalogMeasurements::keyOf($sku)] = new CatalogMeasurements(
                            self::positive($row->weight_kg ?? null),
                            self::volume($row->length_cm ?? null, $row->width_cm ?? null, $row->height_cm ?? null),
                            self::positive($row->category_weight_kg ?? null),
                            self::volume(
                                $row->category_length_cm ?? null,
                                $row->category_width_cm ?? null,
                                $row->category_height_cm ?? null,
                            ),
                        );
                    }
                }

                return $found;
            });
        } catch (Throwable) {
            return [];
        }

        return $result;
    }

    /**
     * Volumen en m³ (L × A × H en cm / 1e6), solo si las tres dimensiones son válidas.
     */
    private static function volume(mixed $length, mixed $width, mixed $height): ?float
    {
        $l = self::positive($length);
        $w = self::positive($width);
        $h = self::positive($height);

        if ($l === null || $w === null || $h === null) {
            return null;
        }

        $volume = round($l * $w * $h / 1_000_000, 6);

        return $volume > 0.0 ? $volume : null;
    }

    private static function positive(mixed $value): ?float
    {
        if (!is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        return is_finite($number) && $number > 0.0 ? $number : null;
    }
}
