<?php

declare(strict_types=1);

namespace Commerce\Catalog\Application\Exception;

use RuntimeException;

/**
 * El producto referenciado por un comando (por id o por SKU canónico) no existe. Es un error de
 * Application (no una regla de negocio): la Presentation lo traduce a 404 en la API.
 */
final class ProductNotFound extends RuntimeException
{
    public static function withSku(string $sku): self
    {
        return new self("No existe un producto con el SKU {$sku}.");
    }

    public static function withId(string $productId): self
    {
        return new self("No existe el producto {$productId}.");
    }

    public static function withoutReference(): self
    {
        return new self('Se requiere el id o el SKU del producto.');
    }
}
