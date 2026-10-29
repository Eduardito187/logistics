<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\ValueObject;

use Commerce\Pricing\Domain\Exception\PricingException;

/**
 * SKU canónico del producto al que se le fija un precio. Value object inmutable.
 *
 * Se normaliza a forma canónica (trim + mayúsculas) para que el mismo SKU escrito de formas distintas
 * ("bel-tv-55" / " BEL-TV-55 ") resuelva a la misma clave natural en la lista de precios. Vacío → error.
 *
 * Mapea 1:1 al SKU de `commerce/catalog` / `inventory`; pricing no duplica los datos del producto, solo
 * referencia su código por valor (ADR 018).
 */
final readonly class Sku
{
    private function __construct(
        public string $value,
    ) {}

    public static function of(string $value): self
    {
        $value = strtoupper(trim($value));
        if ($value === '') {
            throw PricingException::because('El SKU no puede estar vacío.');
        }

        return new self($value);
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
