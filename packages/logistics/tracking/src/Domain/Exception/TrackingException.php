<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de seguimiento: token inválido, transición de estado
 * no permitida o datos de posición/ETA fuera de rango.
 */
final class TrackingException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
