<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de transportistas: transportista inexistente, moneda distinta al
 * operar montos, calificación fuera de rango, corredor mal formado, etc.
 */
final class CarriersException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
