<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Commerce\Catalog\Domain\Exception\CatalogException;

/**
 * SKU canónico de un producto/variante. Se normaliza a mayúsculas sin espacios externos y es el puente
 * 1:1 con el SKU de `inventory` (ATP). No es un UUID: es un identificador de negocio legible.
 */
final readonly class Sku
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtoupper(trim($value));
        if ($normalized === '') {
            throw CatalogException::because('El SKU no puede estar vacío.');
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
