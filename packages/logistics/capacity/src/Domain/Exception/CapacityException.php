<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio de cupos: cantidad negativa, franja mal formada, scope inválido o
 * consumo que excede la capacidad disponible de un bucket.
 */
final class CapacityException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Error de cupos: {$reason}");
    }
}
