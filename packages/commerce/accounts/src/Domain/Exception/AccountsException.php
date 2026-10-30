<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de cuentas: nombre vacío, empresa sin NIT, dirección inválida, etc.
 */
final class AccountsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
