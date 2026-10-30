<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio del carrito: SKU vacío, cantidad inválida, operar sobre un carrito
 * que no está Open, SKU inexistente al actualizar cantidad, falta de disponibilidad, etc.
 */
final class CartException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
