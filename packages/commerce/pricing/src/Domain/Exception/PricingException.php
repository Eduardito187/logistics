<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de pricing: lista inexistente, SKU vacío, monto negativo, moneda
 * distinta a la de la lista, lista archivada, tasa de impuesto fuera de rango, etc.
 */
final class PricingException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
