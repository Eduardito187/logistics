<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de sellers: seller inexistente, NIT vacío, comisión fuera de rango,
 * moneda distinta al operar montos, sku vacío, etc.
 */
final class SellersException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
