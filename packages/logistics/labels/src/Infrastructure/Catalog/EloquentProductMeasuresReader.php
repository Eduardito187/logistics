<?php

declare(strict_types=1);

namespace Logistics\Labels\Infrastructure\Catalog;

use Illuminate\Database\ConnectionInterface;
use Logistics\Labels\Application\Contract\ProductMeasuresReader;
use Logistics\Labels\Domain\ValueObject\ProductMeasures;
use Throwable;

/**
 * Lee las medidas físicas de un producto desde las tablas del catálogo (commerce) en SOLO-LECTURA, para
 * elegir el tamaño de la etiqueta. Etiquetas NO conoce el dominio de catálogo ni lo requiere por composer:
 * referencia cross-context por DATOS (mismo patrón que el enricher), tolerante a que el catálogo todavía
 * no esté desplegado.
 *
 * Fuente (acordado con el contexto catalog):
 *  - `commerce_products` por `canonical_sku` = strtoupper(trim(sku)) (índice único); columnas `weight_kg`
 *    (decimal 10,3), `length_cm`/`width_cm`/`height_cm` (decimal 8,2), `category_id`.
 *  - Fallback a `commerce_categories` (MISMAS 4 columnas) por `category_id` para cada medida que el producto
 *    no tenga propia.
 *  - El VOLUMEN no está en la base: se calcula = length_cm * width_cm * height_cm / 1_000_000 (m³).
 *
 * Todo es nullable. Ante CUALQUIER problema (tabla inexistente, catálogo sin desplegar, error de SQL)
 * devuelve null y la elección de tamaño degrada al perfil Standard: las lecturas van dentro de un SAVEPOINT
 * (transacción anidada) para no abortar la transacción de emisión si algo falla.
 */
final readonly class EloquentProductMeasuresReader implements ProductMeasuresReader
{
    public function __construct(private ConnectionInterface $connection) {}

    public function measuresFor(string $sku): ?ProductMeasures
    {
        $canonical = strtoupper(trim($sku));
        if ($canonical === '') {
            return null;
        }

        try {
            // SAVEPOINT: si la consulta falla (p. ej. la tabla no existe porque catalog no está desplegado),
            // se revierte solo este punto y no se aborta la transacción externa de emisión de etiquetas.
            $result = $this->connection->transaction(fn (): ?ProductMeasures => $this->read($canonical));

            return $result instanceof ProductMeasures ? $result : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function read(string $canonicalSku): ?ProductMeasures
    {
        $product = $this->connection->table('commerce_products')
            ->where('canonical_sku', $canonicalSku)
            ->first(['weight_kg', 'length_cm', 'width_cm', 'height_cm', 'category_id']);

        if ($product === null) {
            return null;
        }

        $weight = self::number($product->weight_kg ?? null);
        $length = self::number($product->length_cm ?? null);
        $width = self::number($product->width_cm ?? null);
        $height = self::number($product->height_cm ?? null);

        // Fallback por categoría para cada medida que el producto no tenga propia.
        $categoryId = $product->category_id ?? null;
        if ($categoryId !== null && ($weight === null || $length === null || $width === null || $height === null)) {
            $category = $this->connection->table('commerce_categories')
                ->where('id', $categoryId)
                ->first(['weight_kg', 'length_cm', 'width_cm', 'height_cm']);

            if ($category !== null) {
                $weight ??= self::number($category->weight_kg ?? null);
                $length ??= self::number($category->length_cm ?? null);
                $width ??= self::number($category->width_cm ?? null);
                $height ??= self::number($category->height_cm ?? null);
            }
        }

        // Volumen derivado (m³) solo si las tres dimensiones están; si falta alguna, queda null.
        $volume = ($length !== null && $width !== null && $height !== null)
            ? $length * $width * $height / 1_000_000
            : null;

        return new ProductMeasures(
            weightKg: $weight,
            lengthCm: $length,
            widthCm: $width,
            heightCm: $height,
            volumeM3: $volume,
        );
    }

    private static function number(mixed $value): ?float
    {
        if ($value === null || !is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        // Un 0 en la base equivale a «sin medida cargada»: no debe disparar la regla de «chico».
        return $number > 0.0 ? $number : null;
    }
}
