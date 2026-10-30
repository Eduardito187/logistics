<?php

declare(strict_types=1);

namespace Commerce\Orders\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de órdenes: orden sin líneas, transición de estado inválida, etc.
 */
final class OrdersException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
