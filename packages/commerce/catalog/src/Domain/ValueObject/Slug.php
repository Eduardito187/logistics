<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\ValueObject;

use Commerce\Catalog\Domain\Exception\CatalogException;

/**
 * Slug de una categoría (segmento de URL amigable). Se normaliza a minúsculas con guiones; no vacío.
 */
final readonly class Slug
{
    private function __construct(public string $value) {}

    public static function of(string $value): self
    {
        $normalized = strtolower(trim($value));
        $normalized = (string) preg_replace('/[^a-z0-9]+/', '-', $normalized);
        $normalized = trim($normalized, '-');
        if ($normalized === '') {
            throw CatalogException::because('El slug no puede estar vacío.');
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
