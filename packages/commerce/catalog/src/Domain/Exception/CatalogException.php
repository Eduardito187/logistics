<?php

declare(strict_types=1);

namespace Commerce\Catalog\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio del catálogo: SKU vacío, variante duplicada, nombre vacío,
 * publicar un producto sin datos mínimos, slug inválido, etc.
 */
final class CatalogException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
