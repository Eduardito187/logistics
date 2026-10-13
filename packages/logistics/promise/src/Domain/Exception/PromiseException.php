<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de promesa de entrega: datos de franja inválidos, una
 * transición de estado no permitida o una reserva vencida al confirmar.
 */
final class PromiseException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
