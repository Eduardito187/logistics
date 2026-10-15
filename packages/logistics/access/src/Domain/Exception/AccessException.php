<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio del contexto Access: permiso/rol inexistente, clave mal formada,
 * credenciales inválidas, token revocado o expirado, intento de borrar un rol de sistema, etc.
 */
final class AccessException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
