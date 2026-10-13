<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de notificaciones: política de cascada inválida, transición
 * de estado no permitida o cascada agotada.
 */
final class NotificationsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
