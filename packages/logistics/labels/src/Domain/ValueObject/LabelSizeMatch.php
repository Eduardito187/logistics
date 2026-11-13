<?php

declare(strict_types=1);

namespace Logistics\Labels\Domain\ValueObject;

/**
 * Cómo matchea una regla de tamaño ({@see LabelSizeRule}) contra un producto:
 *  - `SkuExact`  el SKU es exactamente el valor (comparación insensible a mayúsculas/espacios);
 *  - `SkuPrefix` el SKU empieza con el valor.
 *
 * Hoy solo se puede decidir por SKU (el catálogo todavía no expone grupo de material). El match por
 * categoría/medidas se resuelve por fuera, con las medidas reales del producto (roadmap §4.4).
 */
enum LabelSizeMatch: string
{
    case SkuExact = 'sku_exact';

    case SkuPrefix = 'sku_prefix';

    /** Valor por defecto tolerante cuando un primitivo no reconoce ningún caso. */
    public const LabelSizeMatch DEFAULT = self::SkuExact;

    public static function fromValue(mixed $value): self
    {
        if (!is_string($value)) {
            return self::DEFAULT;
        }

        return self::tryFrom(strtolower(trim($value))) ?? self::DEFAULT;
    }

    /** Nombre legible en inglés para el admin. */
    public function label(): string
    {
        return match ($this) {
            self::SkuExact => 'SKU is exactly',
            self::SkuPrefix => 'SKU starts with',
        };
    }
}
