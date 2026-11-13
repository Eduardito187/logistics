<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de compensación: monto negativo, moneda distinta a BOB, transición
 * de estado ilegal (canjear un vale ya canjeado), cuatro ojos violado, override al alza, etc.
 */
final class CompensationException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
